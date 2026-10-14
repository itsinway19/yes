import { cloneDeep } from 'es-toolkit'
import { decryptHistory, historySessionStorageKeys, prepareHistory, readHistoryMaterial, installHistoryMaterial } from './encryption'
import { navigation, NavigationToken } from './navigation'
import { page as currentPage } from './page'
import Queue from './queue'
import { SessionStorage } from './sessionStorage'
import { Page, ScrollRegion } from './types'
import { HttpCancelledError } from './httpErrors'

const isServer = typeof window === 'undefined'
const queue = new Queue<Promise<unknown>>()
const isChromeIOS = !isServer && /CriOS/.test(window.navigator.userAgent)
const documentReceipt = (page: Page): unknown => {
  const identity = page.props.identity
  return identity && typeof identity === 'object' && 'receipt' in identity ? identity.receipt : undefined
}

class History {
  public rememberedState = 'rememberedState' as const
  public scrollRegions = 'scrollRegions' as const
  protected current: Partial<Page> = {}
  protected initialState: Partial<Page> | null = null

  public remember(data: unknown, key: string): void {
    const candidate = { ...currentPage.getWithoutFlashData(),
      rememberedState: { ...(currentPage.get()?.rememberedState ?? {}), [key]: cloneDeep(data) } }
    void this.writeEntry(candidate, null, true).catch(error => { if (!(error instanceof HttpCancelledError)) navigation.hooks.failure(error, true) })
  }
  public restore(key: string): unknown {
    if (!isServer) return this.current.rememberedState?.[key] !== undefined
      ? this.current.rememberedState[key] : this.initialState?.rememberedState?.[key]
  }
  public processQueue(): Promise<void> { return queue.process() }

  public async commit(page: Page, token: NavigationToken, publish: () => Promise<unknown>,
    snapshot: () => { page: Page; restore: () => Promise<unknown> }, replace: boolean): Promise<void> {
    if (isServer) { await publish(); navigation.finish(token, 'settled'); return }
    try {
      navigation.assert(token)
      const prepared = await navigation.wait(token, prepareHistory(this.clonePage(page)))
      navigation.assert(token)
      await queue.add(async () => {
        const execute = async () => {
          const previous = { ...snapshot(), state: window.history.state, current: this.current,
            entry: navigation.entry, material: readHistoryMaterial() }
          try {
            // Last ownership check and native write share this synchronous callback.
            // No application callback or asynchronous boundary occurs between them.
            navigation.assert(token)
            if (JSON.stringify(previous.material) !== JSON.stringify(prepared.before)) throw new HttpCancelledError('History key ownership changed')
            try {
              installHistoryMaterial(prepared.after)
              this.nativeWrite(replace, { page: prepared.data }, page.url)
            } catch (error) {
              if (navigation.hooks.validate(previous.page)) {
                try { installHistoryMaterial(previous.material) } catch (restoreError) { navigation.hooks.failure(restoreError, false) }
              } else {
                // A changed identity forbids restoration of old key material.
                // Discard the failed candidate's keys and require a fresh document.
                try { installHistoryMaterial({ key: null, iv: null }) } finally { navigation.hooks.failure(error, false) }
              }
              throw error
            }
            token.phase = 'committed'
            token.committed = true
            this.current = page
            this.initialState ??= page
            navigation.acceptEntry()
            const committedRevision = navigation.nativeRevision
            const settlement = publish() // Starts core/adapter assignment synchronously after native success.
            await settlement
            const actual = await this.decrypt()
            if (navigation.nativeRevision !== committedRevision) throw new Error('Native entry moved during settlement')
            if (new URL(page.url, window.location.origin).href !== window.location.href
              || actual.component !== page.component || actual.url !== page.url
              || documentReceipt(actual) !== documentReceipt(page)
              || currentPage.get().component !== page.component || currentPage.get().url !== page.url) {
              throw new Error('History/core settlement mismatch')
            }
            if (!navigation.hooks.validate(token.page!)) throw new Error('Navigation identity changed during settlement')
            navigation.finish(token, 'settled')
          } catch (error) {
            if (token.phase === 'committed') {
              let restored = false
              if (!navigation.isRestoring() && previous.state && new URL(page.url, window.location.origin).href === window.location.href
                && navigation.hooks.validate(previous.page)) {
                try {
                  token.phase = 'compensating'
                  navigation.bind(token, previous.page)
                  installHistoryMaterial(previous.material)
                  this.nativeWrite(true, previous.state, previous.page.url)
                  this.current = previous.current
                  navigation.acceptEntry(previous.entry)
                  await previous.restore()
                  if (!navigation.hooks.validate(previous.page)) throw new Error('Compensation lost identity')
                  restored = true
                } catch { restored = false }
              }
              navigation.hooks.failure(error, restored)
            }
            navigation.finish(token, 'failed')
            throw error
          }
        }
        if (isChromeIOS) await navigation.wait(token, new Promise<void>(resolve => setTimeout(resolve)))
        await Promise.resolve().then(execute)
      })
    } catch (error) {
      if (token.phase === 'preparing' || token.phase === 'cancelled') navigation.finish(token, 'failed')
      throw error
    }
  }

  protected clonePage(page: Page): Page {
    return { ...page, props: cloneDeep(page.props), rememberedState: cloneDeep(page.rememberedState ?? {}) }
  }
  protected nativeWrite(replace: boolean, state: any, url?: string): void {
    if (replace) window.history.replaceState({
      ...state,
      scrollRegions: state.scrollRegions ?? window.history.state?.scrollRegions,
      documentScrollPosition: state.documentScrollPosition ?? window.history.state?.documentScrollPosition,
    }, '', url)
    else window.history.pushState(state, '', url)
  }
  private async writeEntry(page: Page, cb?: (() => void) | null, rememberOnly = false): Promise<void> {
    if (isServer) { cb?.(); return }
    const entry = navigation.entry
    const revision = navigation.nativeRevision
    const captured = this.clonePage(page)
    const assertOwner = () => {
      if (navigation.ownsNative(entry, revision) && navigation.hooks.validate(page)) return
      // Retain a legitimate outgoing draft in its accepted in-memory page only.
      // Never attach it (or its encrypted snapshot) to the traversal target.
      if (rememberOnly && entry === navigation.entry && navigation.isRestoring() && navigation.hooks.validate(page)) {
        this.current = { ...this.current, rememberedState: captured.rememberedState }
        currentPage.remember(captured.rememberedState ?? {})
      }
      throw new HttpCancelledError('Obsolete History entry write')
    }
    await queue.add(async () => {
      assertOwner()
      const prepared = await prepareHistory(captured)
      await Promise.resolve().then(() => {
        assertOwner()
        try {
          installHistoryMaterial(prepared.after)
          this.nativeWrite(true, { page: prepared.data }, captured.url)
        } catch (error) {
          if (navigation.ownsNative(entry, revision) && navigation.hooks.validate(page)) installHistoryMaterial(prepared.before)
          throw error
        }
        this.current = captured
        this.initialState ??= captured
        const { flash, ...withoutFlash } = captured
        currentPage.merge(withoutFlash)
        cb?.()
      })
    })
  }
  public replaceState(page: Page, cb: (() => void) | null = null): Promise<void> { return this.writeEntry(page, cb) }
  public pushState(page: Page, cb: (() => void) | null = null): Promise<void> {
    const token = navigation.start()
    navigation.bind(token, page)
    return this.commit(page, token, async () => { cb?.() },
      () => ({ page: currentPage.get(), restore: async () => {} }), false).finally(() => navigation.forget(token.id))
  }
  public decrypt(page: Page | null = null): Promise<Page> {
    if (isServer) return Promise.resolve(page ?? currentPage.get())
    return this.decryptPageData(page ?? window.history.state?.page).then(data => {
      if (!data) throw new Error('Unable to decrypt history')
      return data // Candidate only; accepted state changes at set/setQuietly.
    })
  }
  protected decryptPageData(data: ArrayBuffer | Page | null): Promise<Page | null> {
    return data instanceof ArrayBuffer ? decryptHistory(data) : Promise.resolve(data)
  }
  public saveScrollPositions(scrollRegions: ScrollRegion[]): void { this.writeScroll({ scrollRegions }) }
  public saveDocumentScrollPosition(documentScrollPosition: ScrollRegion): void { this.writeScroll({ documentScrollPosition }) }
  private writeScroll(change: object): void {
    if (isServer || !window.history.state?.page) return
    const entry = navigation.entry
    const revision = navigation.nativeRevision
    void queue.add(() => {
      if (!navigation.ownsNative(entry, revision) || !navigation.hooks.validate(currentPage.get())) throw new HttpCancelledError('Obsolete scroll entry')
      // Read after preceding remember writes, immediately beside the native write.
      this.nativeWrite(true, { ...window.history.state, ...change })
      return Promise.resolve()
    }).catch(error => { if (!(error instanceof HttpCancelledError)) navigation.hooks.failure(error, true) })
  }
  public getScrollRegions(): ScrollRegion[] { return window.history.state?.scrollRegions || [] }
  public getDocumentScrollPosition(): ScrollRegion { return window.history.state?.documentScrollPosition || { top: 0, left: 0 } }
  public getState<T>(key: keyof Page, defaultValue: T): any { return this.current?.[key] ?? defaultValue }
  public deleteState(key: keyof Page): void {
    if (this.current[key] === undefined) return
    const candidate = { ...this.current }
    delete candidate[key]
    void this.replaceState(candidate as Page).catch(error => navigation.hooks.failure(error, true))
  }
  public clearInitialState(key: keyof Page): void { if (this.initialState) delete this.initialState[key] }
  public browserHasHistoryEntry(): boolean { return !isServer && !!window.history.state?.page }
  public clear(): void {
    navigation.invalidatePreparing()
    navigation.beginTraversal()
    navigation.entry = {}
    SessionStorage.remove(historySessionStorageKeys.key)
    SessionStorage.remove(historySessionStorageKeys.iv)
  }
  public setCurrent(page: Page): void { this.current = page; this.initialState ??= page }
  public isValidState(state: any): boolean { return !!state.page }
  public getAllState(): Page { return this.current as Page }

  public async restoreDocument(page: Page, token: NavigationToken, publish: () => Promise<unknown>): Promise<void> {
    await queue.add(async () => {
      navigation.assert(token)
      if (new URL(page.url, window.location.origin).href !== window.location.href) throw new HttpCancelledError('Obsolete traversal')
      token.phase = 'committed'
      token.committed = true
      this.setCurrent(page)
      navigation.acceptEntry()
      const revision = navigation.nativeRevision
      try {
        await publish()
        if (navigation.nativeRevision !== revision) throw new Error('Native entry moved during restoration settlement')
        if (!navigation.hooks.validate(page)) throw new Error('Restored identity changed')
        navigation.finish(token, 'settled')
      } catch (error) {
        if (navigation.nativeRevision === revision) navigation.beginTraversal()
        navigation.finish(token, 'failed'); navigation.hooks.failure(error, false); throw error
      }
    })
  }
}
if (!isServer && window.history.scrollRestoration) window.history.scrollRestoration = 'manual'
export const history = new History()

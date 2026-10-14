import { cloneDeep } from 'es-toolkit'
import { get, set } from 'es-toolkit/compat'
import { eventHandler } from './eventHandler'
import { fireNavigateEvent } from './events'
import { history } from './history'
import { navigation, NavigationToken } from './navigation'
import { HttpCancelledError } from './httpErrors'
import { prefetchedRequests } from './prefetched'
import { Scroll } from './scroll'
import { Component, FlashData, Page, PageEvent, PageHandler, PageResolver, RouterInitParams, Visit } from './types'
import { hrefToUrl, isSameUrlWithoutHash } from './url'

class CurrentPage {
  protected page!: Page
  protected acceptedComponent?: Component
  protected swapComponent!: PageHandler<any>
  protected resolveComponent!: PageResolver
  protected onFlashCallback?: (flash: Page['flash']) => void
  protected componentId = {}
  protected listeners: {
    event: PageEvent
    callback: VoidFunction
  }[] = []
  protected isFirstPageLoad = true
  protected cleared = false
  protected pendingDeferredProps: Pick<Page, 'deferredProps' | 'url' | 'component'> | null = null
  protected historyQuotaExceeded = false
  protected optimisticBaseline: Partial<Page['props']> = {}
  protected optimisticEntry?: object
  protected pendingOptimistics: { id: number; callback: (props: Page['props']) => Partial<Page['props']> | void }[] = []
  protected optimisticCounter = 0

  public init<ComponentType = Component>({
    initialPage,
    swapComponent,
    resolveComponent,
    onFlash,
  }: RouterInitParams<ComponentType>) {
    this.page = { ...initialPage, flash: initialPage.flash ?? {}, rescuedProps: initialPage.rescuedProps ?? [] }
    this.acceptedComponent = undefined
    this.clearOptimisticState()
    this.swapComponent = swapComponent
    this.resolveComponent = resolveComponent
    this.onFlashCallback = onFlash

    eventHandler.on('historyQuotaExceeded', () => {
      this.historyQuotaExceeded = true
    })

    return this
  }

  public async set(page: Page, {
    replace = false, preserveScroll = false, preserveState = false,
    viewTransition = false, cached = false, initialRender = false, visitId, optimisticBaselines = new Map<string, unknown>(),
  }: { replace?: boolean; preserveScroll?: boolean; preserveState?: boolean;
    viewTransition?: Visit['viewTransition']; cached?: boolean; initialRender?: boolean; visitId?: string;
    optimisticBaselines?: Map<string, unknown> } = {}): Promise<void> {
    const token = navigation.start(visitId)
    const candidate = { ...page, props: cloneDeep(page.props), rememberedState: cloneDeep(page.rememberedState ?? {}) }
    // Bootstrap settles the document already admitted by the application. Keep
    // its immutable identity metadata object: cloning it would invalidate an
    // authentication ceremony captured while initial History encryption waits.
    if (initialRender) candidate.props.identity = page.props.identity
    if (candidate.initialDeferredProps === undefined) candidate.initialDeferredProps = candidate.deferredProps
    navigation.bind(token, candidate)
    try {
      const component = await navigation.wait(token, this.resolve(candidate.component, candidate))
      navigation.assert(token)
      const isServer = typeof window === 'undefined'
      const location = !isServer ? window.location : new URL(candidate.url)
      const scrollRegions = !isServer && preserveScroll ? Scroll.getScrollRegions() : []
      replace = replace || isSameUrlWithoutHash(hrefToUrl(candidate.url), location)
      const isNewComponent = !this.isTheSame(candidate)
      let outgoingEntry: object
      const snapshot = () => {
        const previous = this.page
        outgoingEntry = navigation.entry
        const baseline = cloneDeep(this.optimisticBaseline)
        const pending = [...this.pendingOptimistics]
        const optimisticEntry = this.optimisticEntry
        const previousComponent = this.acceptedComponent ?? (previous.component === candidate.component ? component : undefined)
        return { page: previous, restore: async () => {
          if (!previousComponent) throw new Error('No approved component to compensate')
          this.page = previous
          this.acceptedComponent = previousComponent
          this.optimisticBaseline = baseline
          this.pendingOptimistics = pending
          this.optimisticEntry = optimisticEntry
          await this.swap({ component: previousComponent, page: previous, preserveState: false, viewTransition: false })
        } }
      }
      await history.commit({ ...candidate, flash: {} }, token, async () => {
        if (this.optimisticEntry === outgoingEntry && token.origin === outgoingEntry
          && this.page.url === candidate.url && this.page.component === candidate.component && navigation.hooks.validate(this.page)) {
          this.optimisticEntry = navigation.entry
        }
        this.page = candidate
        for (const [key, value] of optimisticBaselines) this.updateBaseline(key, value)
        this.acceptedComponent = component
        this.cleared = false
        await this.swap({ component, page: candidate, preserveState, viewTransition: false, initialRender })
      }, snapshot, replace)
      // Public lifecycle observers run only after adapter settlement succeeded.
      if (this.hasOnceProps()) prefetchedRequests.updateCachedOncePropsFromCurrentPage()
      if (isNewComponent) this.fireEventsFor('newComponent')
      if (this.isFirstPageLoad) this.fireEventsFor('firstLoad')
      this.isFirstPageLoad = false
      if (!isServer) {
        if (preserveScroll) window.requestAnimationFrame(() => Scroll.restoreScrollRegions(scrollRegions))
        else Scroll.reset()
      }
      if (Object.keys(candidate.deferredProps ?? {}).length) eventHandler.fireInternalEvent('loadDeferredProps', candidate.deferredProps)
      if (!replace) fireNavigateEvent(candidate, { cached, visitId })
    } catch (error) {
      if (token.phase === 'preparing' || token.phase === 'cancelled') navigation.finish(token, 'failed')
      throw error
    } finally { if (!token.request) navigation.forget(token.id) }
  }

  public async setQuietly(page: Page, { preserveState = false, token = navigation.start() }:
    { preserveState?: boolean; token?: NavigationToken } = {}) {
    const candidate = { ...page, props: cloneDeep(page.props), rememberedState: cloneDeep(page.rememberedState ?? {}) }
    navigation.bind(token, candidate)
    try {
      const component = await navigation.wait(token, this.resolve(candidate.component, candidate))
      navigation.assert(token)
      await history.restoreDocument(candidate, token, async () => {
        this.page = candidate
        this.acceptedComponent = component
        this.cleared = false
        await this.swap({ component, page: candidate, preserveState, viewTransition: false })
      })
    } catch (error) { navigation.finish(token, 'failed'); throw error }
    finally { navigation.forget(token.id) }
  }

  public clear(): void {
    this.cleared = true
  }

  public isCleared(): boolean {
    return this.cleared
  }

  public get(): Page {
    return this.page
  }

  public getWithoutFlashData(): Page {
    return { ...this.page, flash: {} }
  }

  public hasOnceProps(): boolean {
    return Object.keys(this.page.onceProps ?? {}).length > 0
  }

  public merge(data: Partial<Page>): void {
    this.page = { ...this.page, ...data }
  }

  public setPropsQuietly(props: Page['props']): Promise<unknown> {
    this.page = { ...this.page, props }
    const candidate = this.page
    const entry = navigation.entry
    return this.resolve(candidate.component, candidate).then((component) => {
      if (entry !== navigation.entry || this.page !== candidate || !navigation.hooks.validate(candidate)) {
        throw new HttpCancelledError('Obsolete accepted-entry update')
      }
      return this.swap({ component, page: candidate, preserveState: true, viewTransition: false })
    })
  }

  public setFlash(flash: FlashData): void {
    this.page = { ...this.page, flash }
    this.onFlashCallback?.(flash)
  }

  public setUrlHash(hash: string): void {
    if (!this.page.url.includes(hash)) {
      this.page.url += hash
    }
  }

  public remember(data: Page['rememberedState']): void {
    this.page.rememberedState = data
  }

  public swap({
    component,
    page,
    preserveState,
    viewTransition,
    initialRender = false,
  }: {
    component: Component
    page: Page
    preserveState: boolean
    viewTransition: Visit['viewTransition']
    initialRender?: boolean
  }): Promise<unknown> {
    const doSwap = () => this.swapComponent({ component, page, preserveState, initialRender })

    if (!viewTransition || !document?.startViewTransition || document.visibilityState === 'hidden') {
      return doSwap()
    }

    const viewTransitionCallback = typeof viewTransition === 'boolean' ? () => null : viewTransition

    // The browser skips this transition when a newer one supersedes it, when the tab goes hidden
    // mid-flight, or when the swap times out, always rejecting with a DOMException. That's
    // expected, so swallow it and let a failing swap reject as it normally would.
    const ignoreSkippedTransition = (promise: Promise<unknown>) => {
      promise.catch((error) => {
        if (!(error instanceof DOMException)) {
          throw error
        }
      })
    }

    return new Promise((resolve) => {
      const transitionResult = document.startViewTransition(() => doSwap().then(resolve))

      ignoreSkippedTransition(transitionResult.ready)
      ignoreSkippedTransition(transitionResult.finished)
      ignoreSkippedTransition(transitionResult.updateCallbackDone)

      viewTransitionCallback(transitionResult)
    })
  }

  public resolve(component: string, page?: Page): Promise<Component> {
    return Promise.resolve(this.resolveComponent(component, page))
  }

  public nextOptimisticId(): number {
    return ++this.optimisticCounter
  }

  public setBaseline(key: string, value: unknown): void {
    if (this.optimisticEntry !== navigation.entry) { this.clearOptimisticState(); this.optimisticEntry = navigation.entry }
    if (!(key in this.optimisticBaseline)) {
      this.optimisticBaseline[key] = value
    }
  }

  public updateBaseline(key: string, value: unknown): void {
    if (this.optimisticEntry === navigation.entry && key in this.optimisticBaseline) {
      this.optimisticBaseline[key] = value
    }
  }

  public hasBaseline(key: string): boolean {
    return this.optimisticEntry === navigation.entry && key in this.optimisticBaseline
  }

  public registerOptimistic(id: number, callback: (props: Page['props']) => Partial<Page['props']> | void): void {
    this.pendingOptimistics.push({ id, callback })
  }

  public unregisterOptimistic(id: number): void {
    this.pendingOptimistics = this.pendingOptimistics.filter((entry) => entry.id !== id)
  }

  public replayOptimistics(): Partial<Page['props']> {
    if (this.optimisticEntry !== navigation.entry) return {}
    const baselineKeys = Object.keys(this.optimisticBaseline)

    if (baselineKeys.length === 0) {
      return {}
    }

    const props = cloneDeep(this.page.props)

    for (const key of baselineKeys) {
      props[key] = cloneDeep(this.optimisticBaseline[key])
    }

    for (const { callback } of this.pendingOptimistics) {
      const result = callback(cloneDeep(props))

      if (result) {
        Object.assign(props, result)
      }
    }

    const replayedProps: Partial<Page['props']> = {}

    for (const key of baselineKeys) {
      replayedProps[key] = props[key]
    }

    return replayedProps
  }

  public pendingOptimisticCount(): number {
    return this.optimisticEntry === navigation.entry ? this.pendingOptimistics.length : 0
  }
  public ownsOptimistic(id: number): boolean {
    return this.optimisticEntry === navigation.entry && this.pendingOptimistics.some(entry => entry.id === id)
  }

  public clearOptimisticState(): void {
    this.optimisticEntry = undefined
    this.optimisticBaseline = {}
    this.pendingOptimistics = []
  }

  public isTheSame(page: Page): boolean {
    return this.page.component === page.component
  }

  public on(event: PageEvent, callback: VoidFunction): VoidFunction {
    this.listeners.push({ event, callback })

    return () => {
      this.listeners = this.listeners.filter((listener) => listener.event !== event && listener.callback !== callback)
    }
  }

  public fireEventsFor(event: PageEvent): void {
    this.listeners.filter((listener) => listener.event === event).forEach((listener) => listener.callback())
  }

  public mergeOncePropsIntoResponse(response: Page, { force = false }: { force?: boolean } = {}): void {
    Object.entries(response.onceProps ?? {}).forEach(([key, onceProp]) => {
      const existingOnceProp = this.page.onceProps?.[key]

      if (existingOnceProp === undefined) {
        return
      }

      if (force || get(response.props, onceProp.prop) === undefined) {
        set(response.props, onceProp.prop, get(this.page.props, existingOnceProp.prop))
        response.onceProps![key].expiresAt = existingOnceProp.expiresAt
      }
    })
  }
}

export const page = new CurrentPage()

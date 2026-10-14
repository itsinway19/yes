import { HttpCancelledError } from './httpErrors'
import { Page } from './types'

type Phase = 'preparing' | 'cancelled' | 'committed' | 'settled' | 'failed' | 'compensating'
export type NavigationToken = { id: string; request: boolean; committed: boolean; phase: Phase; page?: Page; origin: object; traversal?: object; done: Promise<void>; release: () => void }
type Hooks = { validate: (page: Page) => boolean; failure: (error: unknown, restored: boolean) => void }

// Inertia's own pipeline bookkeeping. Neither this token nor its hooks grant authority.
class Navigation {
  private tokens = new Map<string, NavigationToken>()
  private pages = new WeakMap<object, NavigationToken>()
  private preparing?: NavigationToken
  private serial = 0
  public entry: object = {}
  public nativeRevision: object = {}
  private nativeEntry: object | undefined = this.entry
  public hooks: Hooks = { validate: () => true, failure: () => {} }

  // The accepted page can remain in memory while native traversal has already
  // moved to another entry. It no longer owns any native writes during restore.
  public beginTraversal(): object {
    this.nativeEntry = undefined
    this.nativeRevision = {}
    this.invalidatePreparing()
    return this.nativeRevision
  }
  public acceptEntry(entry: object = {}): void {
    this.entry = entry
    this.nativeEntry = entry
    this.nativeRevision = {}
  }
  public ownsNative(entry: object, revision: object): boolean {
    return this.entry === entry && this.nativeEntry === entry && this.nativeRevision === revision
  }
  public isRestoring(): boolean { return this.nativeEntry === undefined }

  public start(id = `local-${++this.serial}`, request = false, supersede = true): NavigationToken {
    const existing = this.tokens.get(id)
    if (existing) { existing.request ||= request; return existing }
    if (supersede) this.invalidatePreparing()
    let release!: () => void
    const token: NavigationToken = { id, request, committed: false, phase: 'preparing', origin: this.entry,
      done: new Promise(resolve => { release = resolve }), release: () => release() }
    this.tokens.set(id, token)
    if (supersede) this.preparing = token
    return token
  }
  public bind(token: NavigationToken, page: Page): void { token.page = page; this.pages.set(page, token) }
  public phase(page: Page): Phase | undefined { return this.pages.get(page)?.phase }
  public cancel(id: string): 'cancelled' | 'already-committed' {
    const token = this.tokens.get(id)
    if (token?.committed) return 'already-committed'
    if (token) { token.phase = 'cancelled'; token.release() }
    return 'cancelled'
  }
  public assert(token: NavigationToken): void {
    if (token.phase !== 'preparing' || !token.page || (token.traversal && token.traversal !== this.nativeRevision)
      || !this.hooks.validate(token.page)) throw new HttpCancelledError('Obsolete navigation candidate')
  }
  public wait<T>(token: NavigationToken, work: Promise<T>): Promise<T> {
    return Promise.race([work, token.done.then(() => {
      throw new HttpCancelledError('Navigation preparation cancelled')
    })])
  }
  public invalidatePreparing(): void {
    for (const token of this.tokens.values()) if (token.phase === 'preparing') this.cancel(token.id)
  }
  public finish(token: NavigationToken, phase: 'settled' | 'failed'): void {
    token.phase = phase; token.release()
    if (this.preparing === token) this.preparing = undefined
  }
  public forget(id: string): void { this.tokens.delete(id) }
  public failed(id: string): boolean { return this.tokens.get(id)?.phase === 'failed' }
}

export const navigation = new Navigation()
export function configureNavigation(hooks: Hooks): void { navigation.hooks = hooks }
export function navigationPhase(page: Page): Phase | undefined { return navigation.phase(page) }

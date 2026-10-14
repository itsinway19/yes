import { get } from 'es-toolkit/compat'
import debounce from './debounce'
import { fireNavigateEvent } from './events'
import { history } from './history'
import { navigation } from './navigation'
import { HttpCancelledError } from './httpErrors'
import { router } from './index'
import { page as currentPage } from './page'
import { Scroll } from './scroll'
import { GlobalEvent, GlobalEventNames, GlobalEventResult, InternalEvent } from './types'
import { hrefToUrl } from './url'

class EventHandler {
  protected internalListeners: {
    event: InternalEvent
    listener: (...args: any[]) => void
  }[] = []

  public init() {
    if (typeof window !== 'undefined') {
      window.addEventListener('popstate', this.handlePopstateEvent.bind(this))
      window.addEventListener('pageshow', this.handlePageshowEvent.bind(this))
      window.addEventListener('scroll', debounce(Scroll.onWindowScroll.bind(Scroll), 100), true)
    }

    if (typeof document !== 'undefined') {
      document.addEventListener('scroll', debounce(Scroll.onScroll.bind(Scroll), 100), true)
    }
  }

  public onGlobalEvent<TEventName extends GlobalEventNames>(
    type: TEventName,
    callback: (event: GlobalEvent<TEventName>) => GlobalEventResult<TEventName>,
  ): VoidFunction {
    const listener = ((event: GlobalEvent<TEventName>) => {
      const response = callback(event)

      if (event.cancelable && !event.defaultPrevented && response === false) {
        event.preventDefault()
      }
    }) as EventListener

    return this.registerListener(`inertia:${type}`, listener)
  }

  public on(event: InternalEvent, callback: (...args: any[]) => void): VoidFunction {
    this.internalListeners.push({ event, listener: callback })

    return () => {
      this.internalListeners = this.internalListeners.filter((listener) => listener.listener !== callback)
    }
  }

  public onMissingHistoryItem() {
    // At this point, the user has probably cleared the state
    // Mark the current page as cleared so that we don't try to write anything to it.
    currentPage.clear()
    // Fire an event so that that any listeners can handle this situation
    this.fireInternalEvent('missingHistoryItem')
  }

  public fireInternalEvent(event: InternalEvent, ...args: any[]): void {
    this.internalListeners
      .filter((listener) => listener.event === event)
      .forEach((listener) => listener.listener(...args))
  }

  protected registerListener(type: string, listener: EventListener): VoidFunction {
    document.addEventListener(type, listener)

    return () => document.removeEventListener(type, listener)
  }

  // bfcache restores pages without firing `popstate`, so we use `pageshow` to
  // re-validate encrypted history entries after `clearHistory` removed the keys.
  // https://web.dev/articles/bfcache
  protected handlePageshowEvent(event: PageTransitionEvent): void {
    if (event.persisted) {
      history.decrypt().catch(() => this.onMissingHistoryItem())
    }
  }

  protected handlePopstateEvent(event: PopStateEvent): void {
    const traversal = navigation.beginTraversal()
    const state = event.state || null

    if (state === null) {
      const url = hrefToUrl(currentPage.get().url)
      if (url.pathname !== window.location.pathname || url.search !== window.location.search) return this.onMissingHistoryItem()
      url.hash = window.location.hash
      void currentPage.set({ ...currentPage.getWithoutFlashData(), url: url.href }, { replace: true })
        .catch(error => { if (!(error instanceof HttpCancelledError)) this.onMissingHistoryItem() })

      return
    }

    if (!history.isValidState(state)) {
      return this.onMissingHistoryItem()
    }

    router.cancelAll({ prefetch: false })
    const token = navigation.start()
    token.traversal = traversal
    navigation.wait(token, history.decrypt(state.page))
      .then((data) => {
        if (navigation.nativeRevision !== traversal) throw new HttpCancelledError('Obsolete traversal decrypt')
        if (currentPage.get().version !== data.version) {
          this.onMissingHistoryItem()
          return
        }

        return currentPage.setQuietly(data, { preserveState: false, token }).then(() => {
          Scroll.restore(history.getScrollRegions())
          fireNavigateEvent(currentPage.get())

          const pendingDeferred: Record<string, string[]> = {}
          const pageProps = currentPage.get().props

          for (const [group, props] of Object.entries(data.initialDeferredProps ?? data.deferredProps ?? {})) {
            const missing = props.filter((prop) => get(pageProps, prop) === undefined)

            if (missing.length > 0) {
              pendingDeferred[group] = missing
            }
          }

          if (Object.keys(pendingDeferred).length > 0) {
            this.fireInternalEvent('loadDeferredProps', pendingDeferred)
          }
        })
      })
      .catch((error) => {
        if (!(error instanceof HttpCancelledError)) this.onMissingHistoryItem()
        else if (navigation.nativeRevision === traversal && navigation.isRestoring()) navigation.hooks.failure(error, false)
      })
      .finally(() => navigation.forget(token.id))
  }
}

export const eventHandler = new EventHandler()

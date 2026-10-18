import { eventHandler } from './eventHandler'
import { fireFlashEvent, fireNavigateEvent } from './events'
import { history } from './history'
import { navigationType } from './navigationType'
import { page as currentPage } from './page'
import { Scroll } from './scroll'
import { SessionStorage } from './sessionStorage'
import { LocationVisit } from './types'
import { uid } from './uid'
import { navigation } from './navigation'
import { HttpCancelledError } from './httpErrors'

export class InitialVisit {
  public static handle(): void {
    this.clearRememberedStateOnReload()

    const scenarios = [this.handleBackForward, this.handleLocation, this.handleDefault]

    scenarios.find((handler) => handler.bind(this)())
  }

  protected static clearRememberedStateOnReload(): void {
    if (navigationType.isReload()) {
      history.deleteState(history.rememberedState)
      history.clearInitialState(history.rememberedState)
    }
  }

  protected static handleBackForward(): boolean {
    if (!navigationType.isBackForward() || !history.browserHasHistoryEntry()) {
      return false
    }

    const scrollRegions = history.getScrollRegions()
    const visitId = uid()
    const token = navigation.start(visitId)

    history
      .decrypt()
      .then((data) => {
        navigation.bind(token, data)
        navigation.assert(token)
        return currentPage.set(data, { preserveScroll: true, preserveState: true, visitId }).then(() => {
          Scroll.restore(scrollRegions)
          fireNavigateEvent(currentPage.get(), { visitId })
        })
      })
      .catch((error) => {
        if (!(error instanceof HttpCancelledError)) eventHandler.onMissingHistoryItem()
      })
      .finally(() => navigation.forget(visitId))

    return true
  }

  /**
   * @link https://inertiajs.com/redirects#external-redirects
   */
  protected static handleLocation(): boolean {
    if (!SessionStorage.exists(SessionStorage.locationVisitKey)) {
      return false
    }

    const locationVisit: LocationVisit = SessionStorage.get(SessionStorage.locationVisitKey) || {}
    const visitId = uid()
    const token = navigation.start(visitId)

    SessionStorage.remove(SessionStorage.locationVisitKey)

    if (typeof window !== 'undefined') {
      currentPage.setUrlHash(window.location.hash)
    }

    history
      .decrypt(currentPage.get())
      .then((data) => {
        navigation.bind(token, data)
        navigation.assert(token)
        const rememberedState = data.rememberedState ?? {}
        const scrollRegions = history.getScrollRegions()

        return currentPage
          .set({ ...currentPage.get(), rememberedState }, {
            preserveScroll: locationVisit.preserveScroll,
            preserveState: true,
            initialRender: true,
            visitId,
          })
          .then(() => {
            if (locationVisit.preserveScroll) {
              Scroll.restore(scrollRegions)
            }

            this.fireInitialEvents(visitId)
          })
      })
      .catch((error) => {
        if (!(error instanceof HttpCancelledError)) eventHandler.onMissingHistoryItem()
      })
      .finally(() => navigation.forget(visitId))

    return true
  }

  protected static handleDefault(): void {
    if (typeof window !== 'undefined') {
      currentPage.setUrlHash(window.location.hash)
    }

    const visitId = uid()

    currentPage
      .set(currentPage.get(), { preserveScroll: true, preserveState: true, initialRender: true, visitId })
      .then(() => {
        if (navigationType.isReload()) {
          Scroll.restore(history.getScrollRegions())
        } else {
          Scroll.scrollToAnchor()
        }

        this.fireInitialEvents(visitId)
      })
      .catch((error) => {
        if (!(error instanceof HttpCancelledError)) throw error
      })
  }

  protected static fireInitialEvents(visitId: string): void {
    const page = currentPage.get()

    fireNavigateEvent(page, { visitId })

    if (Object.keys(page.flash).length > 0) {
      queueMicrotask(() => fireFlashEvent(page.flash))
    }
  }
}

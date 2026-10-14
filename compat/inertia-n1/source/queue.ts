export default class Queue<T> {
  private tail: Promise<void> = Promise.resolve()
  public add(item: () => T): Promise<Awaited<T>> {
    const result = this.tail.then(item)
    // Each caller receives its real outcome; failure cannot strand later items.
    this.tail = result.then(() => {}, () => {})
    // Promise resolution assimilates a returned promise/thenable at runtime.
    return result as Promise<Awaited<T>>
  }
  public process(): Promise<void> { return this.tail }
}

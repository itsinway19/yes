import { SessionStorage } from './sessionStorage'

export const encryptHistory = async (data: any): Promise<ArrayBuffer> => {
  if (typeof window === 'undefined') {
    throw new Error('Unable to encrypt history')
  }

  const iv = getIv()
  const storedKey = await getKeyFromSessionStorage()
  const key = await getOrCreateKey(storedKey)

  if (!key) {
    throw new Error('Unable to encrypt history')
  }

  const encrypted = await encryptData(iv, key, data)

  return encrypted
}

export const historySessionStorageKeys = {
  key: 'historyKey',
  iv: 'historyIv',
}

export const decryptHistory = async (data: any): Promise<any> => {
  const storedIv = SessionStorage.get(historySessionStorageKeys.iv)
  if (!storedIv) throw new Error('Missing history IV')
  const iv = new Uint8Array(storedIv)
  const storedKey = await getKeyFromSessionStorage()

  if (!storedKey) {
    throw new Error('Unable to decrypt history')
  }

  return await decryptData(iv, storedKey, data)
}

const encryptData = async (iv: BufferSource, key: CryptoKey, data: any) => {
  if (typeof window === 'undefined') {
    throw new Error('Unable to encrypt history')
  }

  if (typeof window.crypto.subtle === 'undefined') {
    console.warn('Encryption is not supported in this environment. SSL is required.')

    throw new Error('History encryption requires WebCrypto')
  }

  const textEncoder = new TextEncoder()
  const str = JSON.stringify(data)
  const encoded = new Uint8Array(str.length * 3)

  const result = textEncoder.encodeInto(str, encoded)

  return window.crypto.subtle.encrypt(
    {
      name: 'AES-GCM',
      iv,
    },
    key,
    encoded.subarray(0, result.written),
  )
}

const decryptData = async (iv: BufferSource, key: CryptoKey, data: any) => {
  if (typeof window.crypto.subtle === 'undefined') {
    console.warn('Decryption is not supported in this environment. SSL is required.')

    throw new Error('History decryption requires WebCrypto')
  }

  const decrypted = await window.crypto.subtle.decrypt(
    {
      name: 'AES-GCM',
      iv,
    },
    key,
    data,
  )

  return JSON.parse(new TextDecoder().decode(decrypted))
}

const getIv = (): BufferSource => {
  const ivString = SessionStorage.get(historySessionStorageKeys.iv)

  if (ivString) {
    return new Uint8Array(ivString)
  }

  const iv = window.crypto.getRandomValues(new Uint8Array(12))

  SessionStorage.set(historySessionStorageKeys.iv, Array.from(iv))

  return iv
}

const createKey = async () => {
  if (typeof window.crypto.subtle === 'undefined') {
    console.warn('Encryption is not supported in this environment. SSL is required.')

    return Promise.resolve(null)
  }

  return window.crypto.subtle.generateKey(
    {
      name: 'AES-GCM',
      length: 256,
    },
    true,
    ['encrypt', 'decrypt'],
  )
}

const saveKey = async (key: CryptoKey) => {
  if (typeof window.crypto.subtle === 'undefined') {
    console.warn('Encryption is not supported in this environment. SSL is required.')

    return Promise.resolve()
  }

  const keyData = await window.crypto.subtle.exportKey('raw', key)

  SessionStorage.set(historySessionStorageKeys.key, Array.from(new Uint8Array(keyData)))
}

const getOrCreateKey = async (key: CryptoKey | null) => {
  if (key) {
    return key
  }

  const newKey = await createKey()

  if (!newKey) {
    return null
  }

  await saveKey(newKey)

  return newKey
}

const getKeyFromSessionStorage = async (): Promise<CryptoKey | null> => {
  const stringKey = SessionStorage.get(historySessionStorageKeys.key)

  if (!stringKey) {
    return null
  }

  const key = await window.crypto.subtle.importKey(
    'raw',
    new Uint8Array(stringKey),
    {
      name: 'AES-GCM',
      length: 256,
    },
    true,
    ['encrypt', 'decrypt'],
  )

  return key
}

export type HistoryMaterial = { key: string | null; iv: string | null }
export const readHistoryMaterial = (): HistoryMaterial => ({
  key: window.sessionStorage.getItem(historySessionStorageKeys.key),
  iv: window.sessionStorage.getItem(historySessionStorageKeys.iv),
})
export const installHistoryMaterial = (material: HistoryMaterial): void => {
  for (const name of ['key', 'iv'] as const) {
    const value = material[name]
    if (value === null) window.sessionStorage.removeItem(historySessionStorageKeys[name])
    else window.sessionStorage.setItem(historySessionStorageKeys[name], value)
  }
}
export const prepareHistory = async (page: any) => {
  const before = readHistoryMaterial()
  let after = page.clearHistory ? { key: null, iv: null } : { ...before }
  if (!page.encryptHistory) return { data: page, before, after }
  if (!window.crypto?.subtle) throw new Error('History encryption requires WebCrypto')
  const iv = after.iv ? new Uint8Array(JSON.parse(after.iv)) : window.crypto.getRandomValues(new Uint8Array(12))
  const key = after.key
    ? await window.crypto.subtle.importKey('raw', new Uint8Array(JSON.parse(after.key)), 'AES-GCM', true, ['encrypt', 'decrypt'])
    : await window.crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, true, ['encrypt', 'decrypt'])
  const keyBytes = after.key ?? JSON.stringify(Array.from(new Uint8Array(await window.crypto.subtle.exportKey('raw', key))))
  after = { key: keyBytes, iv: after.iv ?? JSON.stringify(Array.from(iv)) }
  const data = await encryptData(iv, key, page)
  // Key/IV remain candidate-local until native commitment; failures publish nothing.
  return { data, before, after }
}

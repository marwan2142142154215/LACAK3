import QRCode from 'qrcode'

export async function createTotpQrDataUrl(otpauthUri: string): Promise<string> {
  let uri: URL

  try {
    uri = new URL(otpauthUri)
  } catch {
    throw new Error('Invalid TOTP enrollment URI.')
  }

  if (uri.protocol !== 'otpauth:' || uri.hostname !== 'totp' || !uri.searchParams.has('secret')) {
    throw new Error('Invalid TOTP enrollment URI.')
  }

  const svg = await QRCode.toString(otpauthUri, {
    type: 'svg',
    errorCorrectionLevel: 'M',
    margin: 2,
    width: 224,
    color: { dark: '#102541', light: '#ffffff' },
  })

  return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`
}

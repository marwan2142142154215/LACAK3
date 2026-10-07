import { describe, expect, it } from 'vitest'
import { createTotpQrDataUrl } from './totpQr'

describe('TOTP enrollment QR', () => {
  it('creates a local SVG QR image from the server enrollment URI', async () => {
    const image = await createTotpQrDataUrl('otpauth://totp/SMB%3Amaster?secret=JBSWY3DPEHPK3PXP&issuer=SMB')

    expect(image).toMatch(/^data:image\/svg\+xml;charset=utf-8,/)
    expect(decodeURIComponent(image.split(',')[1])).toContain('<svg')
    expect(decodeURIComponent(image.split(',')[1])).toContain('<path')
  })

  it('rejects non-TOTP URIs so the QR cannot point to an arbitrary destination', async () => {
    await expect(createTotpQrDataUrl('https://example.com/?secret=abc')).rejects.toThrow('Invalid TOTP enrollment URI.')
  })
})

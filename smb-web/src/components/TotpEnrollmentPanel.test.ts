import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import TotpEnrollmentPanel from './TotpEnrollmentPanel.vue'

describe('TOTP enrollment panel', () => {
  it('renders a local scannable QR and manual-key fallback', async () => {
    const wrapper = mount(TotpEnrollmentPanel, {
      props: {
        otpauthUri: 'otpauth://totp/SMB%3Amaster?secret=JBSWY3DPEHPK3PXP&issuer=SMB',
        secret: 'JBSWY3DPEHPK3PXP',
      },
    })
    await flushPromises()

    const qr = wrapper.find('img[alt="QR code untuk menyiapkan autentikasi dua faktor"]')
    expect(qr.exists()).toBe(true)
    expect(qr.attributes('src')).toMatch(/^data:image\/svg\+xml;charset=utf-8,/)
    expect(wrapper.text()).toContain('JBSWY3DPEHPK3PXP')
    expect(wrapper.text()).not.toContain('otpauth://')
  })

  it('shows the manual key with a clear fallback when the enrollment URI is invalid', async () => {
    const wrapper = mount(TotpEnrollmentPanel, {
      props: { otpauthUri: 'invalid-uri', secret: 'MANUAL-SETUP-KEY' },
    })
    await flushPromises()

    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.text()).toContain('QR tidak dapat dibuat di browser ini.')
    expect(wrapper.text()).toContain('MANUAL-SETUP-KEY')
  })
})

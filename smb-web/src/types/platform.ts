export type DeviceStatus = 'ONLINE' | 'DEGRADED' | 'OFFLINE' | 'UNKNOWN'

export interface UserSummary {
  id: string | number
  name: string
  username: string
  email: string | null
  roles: string[]
  permissions: string[]
}

export interface DeviceLocation {
  id: number
  latitude: string | number
  longitude: string | number
  accuracy_meters: string | number | null
  recorded_at: string
  received_at: string
  source: string
}

export interface Device {
  id: string
  name: string
  manufacturer: string | null
  model: string | null
  site_id: number
  site?: { id: number; name: string; code: string }
  team_id: number
  team?: { id: number; name: string }
  status: DeviceStatus
  connection_state: string
  lock_state: string
  last_seen_at: string | null
  last_location_at: string | null
  latest_location?: DeviceLocation | null
  battery_percent: number | null
  network_type: string | null
  android_version: string | null
  android_api: number | null
  app_version: string | null
  app_version_code: number | null
  capabilities: Record<string, boolean>
  managed_device: boolean
  device_owner: boolean
}

export interface Site {
  id: number
  code: string
  name: string
  timezone: string
  is_active: boolean
  teams_count?: number
  devices_count?: number
  allowed_wifi_ssids?: string[]
  allowed_ip_ranges?: string[]
}

export interface NetworkViolation {
  id: number
  device_id: string
  site_id: number
  reason: string
  observed_value: string | null
  status: 'OPEN' | 'RESOLVED'
  occurrences: number
  first_seen_at: string
  last_seen_at: string
  resolved_at: string | null
  device: { id: string; name: string; network_type: string | null; last_seen_at: string | null }
  site: { id: number; name: string; code: string }
}

export interface Team {
  id: number
  site_id: number
  name: string
  is_active: boolean
  site?: Pick<Site, 'id' | 'name' | 'code'>
  devices_count?: number
}

export interface DeviceCommand {
  command_id: string
  device_id: string
  command_type: string
  sequence: number
  status: string
  created_at: string
  expires_at: string
  sent_at?: string | null
  delivered_at?: string | null
  received_at?: string | null
  executing_at?: string | null
  completed_at?: string | null
  result_code?: string | null
  result_message?: string | null
}

export interface DeviceSession {
  id: string
  type: string
  name: string
  ip_address: string | null
  user_agent: string | null
  last_activity_at: string
  expires_at?: string | null
  is_current: boolean
}

export interface TelegramAccount {
  id: number
  telegram_id: string
  is_active: boolean
  approved_at: string | null
  last_seen_at: string | null
  created_at: string
  user: Pick<UserSummary, 'id' | 'name' | 'username' | 'email'>
  approver?: Pick<UserSummary, 'id' | 'name'> | null
}

export interface TelegramEligibleUser {
  id: number
  name: string
  username: string
  email: string | null
}

export interface AuditLogEntry {
  id: number
  log_name: string | null
  event: string
  subject_type: string | null
  subject_id: string | null
  actor: { id: number | string; name: string } | null
  properties: Record<string, unknown>
  created_at: string | null
}

export interface ServiceHealth {
  status: string
  checked_at: string
  services: Record<string, { status: string; reason?: string }>
}

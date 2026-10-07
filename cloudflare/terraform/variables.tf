variable "zone_id" {
  description = "Cloudflare zone ID for lacaksmbbot.com (not the account ID)."
  type        = string
}

variable "login_requests_per_minute" {
  description = "Per-source-IP request threshold for login and second-factor verification."
  type        = number
  default     = 10
  validation {
    condition     = var.login_requests_per_minute >= 5 && var.login_requests_per_minute <= 60
    error_message = "Use a login limit between 5 and 60 requests per minute."
  }
}

variable "mitigation_seconds" {
  description = "How long Cloudflare blocks a source after the login threshold is reached."
  type        = number
  default     = 60
  validation {
    condition     = var.mitigation_seconds >= 10 && var.mitigation_seconds <= 600
    error_message = "Use a mitigation window between 10 and 600 seconds."
  }
}

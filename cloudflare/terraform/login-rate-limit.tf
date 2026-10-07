resource "cloudflare_ruleset" "smb_api_login_rate_limit" {
  zone_id     = var.zone_id
  name        = "SMB API login rate limit"
  description = "Limits password and second-factor login attempts before they reach the origin."
  kind        = "zone"
  phase       = "http_ratelimit"

  rules {
    action      = "block"
    description = "Block bursts to SMB login endpoints per source IP"
    enabled     = true
    expression  = "(http.host eq \"api.lacaksmbbot.com\" and http.request.method eq \"POST\" and http.request.uri.path in {\"/api/v1/auth/login\" \"/api/v1/auth/login/verify\"})"
    ratelimit {
      characteristics     = ["ip.src"]
      period              = 60
      requests_per_period = var.login_requests_per_minute
      mitigation_timeout  = var.mitigation_seconds
    }
  }
}

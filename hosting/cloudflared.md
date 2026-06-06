# cloudflared ingress + DNS for a new sub-site

1. **Ingress** in `~/.cloudflared/<tunnel>-config.yml` — insert BEFORE `service: http_status:404`:
   ```yaml
     - hostname: SUBDOMAIN.example.com
       service: http://localhost:PORT
   ```
2. **DNS** (CNAME -> tunnel) via the tunnel credentials:
   ```bash
   cloudflared tunnel route dns <TUNNEL_ID> SUBDOMAIN.example.com
   ```
3. **Restart**: `sudo systemctl restart cloudflared-<name>`

## Gotchas
- **Verify via public DNS** (`dig @8.8.8.8`), not over a VPN/tailnet (MagicDNS gives false OK).
- urllib/Python gets a **403** from Cloudflare (bot UA) — test with curl and a browser UA.
- Pretty permalinks need a WP `.htaccess` (the dev `wp server` doesn't create one).
- Cloudflare **Email Obfuscation** (zone setting) rewrites `mail@x` to `[email protected]`.

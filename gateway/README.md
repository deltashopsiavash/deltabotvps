# Delta quota gateway — isolated prototype, NOT production deployment

## Boundary
A dedicated gateway must authenticate a unique peer per app account (WireGuard public key with exclusive AllowedIPs /32). All user traffic must traverse this gateway; shared source credentials must never be delivered directly to clients in the enforced architecture. Existing bot/app remain unchanged. The gateway's egress routing into the shared upstream Xray nodes and Android peer enrollment are separate, not implemented here.

The kernel gate uses one named quota per account across ingress and egress, and direction-specific counters only after quota admission. 1 plan GB = 1,073,741,824 bytes (GiB, matching previous app convention). The meter counts IP packet bytes admitted at the gateway, including IP headers and retransmissions, not encrypted outer tunnel overhead, not application payload. A whole packet crossing the cap is rejected; last unused bytes can be less than one packet. Both TCP and UDP are subject to the same gate. Unknown peers and IPv6 on the dedicated interface are dropped. Expiry uses kernel timed set membership, not Android wall clock.

## Safety and persistence gaps
- Never install on the production bot host or a shared VPN interface. This component does not configure WireGuard, firewall baseline, forwarding, NAT, Xray routes, or key distribution.
- The dedicated interface must remain down or blocked by a separate persistent default-deny firewall until the gate is installed, and after reboot until counters have been reconciled. Kernel state is volatile; do not recreate a gate after reboot using stale counts. A private fsynced local checkpoint prevents silent reinstall and retains the last observed counters. No automatic reconciliation or persistent central ledger is implemented; unobserved traffic after a kernel crash cannot be reconstructed. This prevents a claim of production-ready billing.
- Only one metered gateway per account is supported by this prototype. No multipath or cross-gateway quotas.
- A snapshot uses the original baseline config that was used to install the rules. Never reset live counters. A missing counter is unknown, not zero. Admitted packet counts may include packets subsequently dropped by other network layers; the accounting boundary is explicitly the gateway gate.
- Test with namespaces and real WireGuard, test reboot/expiry/revocation and implement durable accounting before app integration. Existing shared credentials already delivered to test clients must be rotated before enforced sales.

## Commands for isolated lab only
```
python3 quota_gate.py render lab.json
sudo python3 quota_gate.py check lab.json
sudo python3 quota_gate.py install-once lab.json --state-dir /var/lib/delta-quota-lab
sudo python3 quota_gate.py snapshot lab.json --state-dir /var/lib/delta-quota-lab
python3 -m unittest discover -s . -p 'test_*.py'
```
Config example (expiry must be a future UTC Unix timestamp; replace with lab values):
```json
{"interface":"wgdelta","accounts":[{"id":1,"ip":"10.120.0.2","gb":10,"used_bytes":0,"expires":1800000000,"active":true}]}
```

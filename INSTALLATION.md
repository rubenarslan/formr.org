# Setup instructions for formr

Until recently, we admitted new users to an instance of formr hosted at the University of Goettingen. This instance is now at capacity. We now recommend self-hosting, ideally using a professional web hoster.

formr can run on Linux, Mac OS and Windows. In its dockerized form, differences between platform should not cause problems. However, OpenCPU in production requires a Linux host (for AppArmor to work).

## Contribute to development, test locally
To install formr locally, either to test it out, or to contribute to development, we recommend using a dockerized version, which helpfully includes a docker compose environment for the database and OpenCPU too.
You can find detailed instructions here:
https://github.com/rubenarslan/formr_dev_docker

## Production
If you want to run formr in production, you have to be aware of multiple best practices.

Pain points include:
- formr, via OpenCPU, allows study creators to run arbitrary R code, so
    - OpenCPU needs to be secured via AppArmor
    - Access to running and viewing code run on OpenCPU needs to be restricted
- OpenCPU freezes R packages at each release, installing additional packages needs to be done with care, so as not to lead to version conflicts
- Research data should be encrypted at rest
- Subdomains per study are part of formr's security concept (separation of concerns, avoid cross-site scripting)
- Only encrypted connections to formr and OpenCPU should be possible
- Daemons for email sending and study progress need to be running continuously

The developers of formr (Ruben and Cyril) are available for consulting to make a standardized, dockerized production version of formr available. As the number of users has grown, we can no longer offer free support to everybody who installs formr in a non-standard way.

### Outbound mail: abuse hardening and deliverability

A publicly reachable formr instance is a mail-sending machine with a signup
form in front of it. The failure mode looks like this: bots register accounts
(or hammer the forgot-password form) with addresses that are fake or belong
to strangers; formr dutifully mails each one; the recipients complain; your
provider sees an authenticated mailbox emitting mail to unrelated strangers
and disables SMTP for the whole account — taking every study's mail down with
it. This is subscription bombing, your instance is one of many forms being
used for it, and it will find any open instance eventually.

What controls it (all in `config/settings.php`, see the comments in
`config-dist/settings.php`):

- **`registration.mode`** — the single most effective setting. Most lab
  instances need accounts for a handful of researchers and hand participants
  session links, so `domains` (institutional addresses only), `invite`
  (tokens from `bin/registration_invite.php`) or `closed` removes the signup
  attack surface entirely. `open` on a public instance means accepting this
  class of abuse.
- **`mail_guard`** — every outbound message is checked against a suppression
  list (`bin/suppression.php`) and logged; verification and password-reset
  mail additionally get a duplicate-send window and per-IP / per-subnet /
  instance-wide hourly ceilings, and recipients' domains are checked for MX
  records. Watch `admin/advanced/mail_guard` — a tripped ceiling means
  someone is probing you.
- The signup form always carries an invisible honeypot and a signed
  timestamp; `registration.challenge = 'altcha'` adds a self-hosted
  proof-of-work widget with no third-party data flow.

Deliverability: send transactional mail through a provider built for it, with
SPF, DKIM and DMARC configured on a dedicated subdomain (e.g.
`mail.your-instance.example`), rather than through a shared hoster's SMTP.
A transactional provider gives you bounce and complaint feedback and
per-message logs; a shared hoster gives you one complaint threshold and a
disabled mailbox. Keep the study-mail accounts your researchers configure
separate from the system account used for verification and reset mail, so one
study's trouble cannot take down account management.

If an instance has already been abused: switch `registration.mode` to a
restricted mode, add the targeted addresses to the suppression list, rotate
the SMTP password, and tell your provider what happened and what you changed
— all of that works without upgrading.

### The formr R package

These are the instructions to run a local or online copy of the formr.org distribution. It is much easier to install the [R package](https://github.com/rubenarslan/formr) if that's what you're looking for.

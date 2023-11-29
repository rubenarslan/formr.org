# Setup instructions for formr

Until recently, we admitted new users to an instance of formr hosted at the University of Goettingen. This instance is now at capacity. We now recommend self-hosting, ideally using a professional web hoster.

formr can run on Linux, Mac OS and Windows. In its dockerized form, differences between platform should not cause problems. However, OpenCPU in production requires a Linux host (for AppArmor to work).

## Contribute to development, test locally
To install formr locally, either to test it out, or to contribute to development, we recommend using a dockerized version, which helpfully includes a docker compose environment for the database and OpenCPU too.
You can find detailed instructions here:
https://github.com/rubenarslan/formr_dev_docker

The installation instructions detailed below are for a Debian 9 Environment but can be modified accordingly for other platforms.

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

### The formr R package

These are the instructions to run a local or online copy of the formr.org distribution. It is much easier to install the [R package](https://github.com/rubenarslan/formr) if that's what you're looking for.
# Installation

There are two ways to install 'Formr.' You can either do it in the traditional manual way or by using Docker.

---
* [Docker (Recommended)](#docker-recommended)
  * Build
    * [open-CPU](#open-cpu)
  * Configuration
    * 
  * Run
    * 
* [Manual](#manual)
---
# Docker *(Recommended)*
## Build the Images
### open-CPU
You can either build your own open-CPU Image or you can use our image.
#### Build your own
You can use the Dockerfile ```./docker/opencpu/Dockerfile``` to build your own Image
#### Use ours 
You can download our open-CPU Image in case you are ___running formr on an x-86 platform___.
```bash
docker pull ghcr.io/timed-and-secured-assets/opencpu:master
```
### Formr
In theory, you can also use your formr image (```ghcr.io/timed-and-secured-assets/formr.org:master```), 
but we highly recommend building your own image, because you can only customize your own image.

You can use the Dockerfile ```./Dockerfile``` to build your own Image

## Configuration
Before we can run Formr we need to set some configurations:

### Settings.php
You need to change the ```./docker/settings.php```. We recommend that you read through the

## Run

# Manual
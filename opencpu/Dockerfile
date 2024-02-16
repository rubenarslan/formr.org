# Dockerfile for opencpou and formr required R packages
# Author: Elias-Leander Ahlers
# Date: 16.10.2023

# Use a base image with Ubuntu
FROM ubuntu:latest

# Supress apt installation prompts
ARG DEBIAN_FRONTEND=noninteractive

# Update apt repository, add ppa for opencpu & install opencpu
RUN apt-get update && apt-get install -y software-properties-common && add-apt-repository -y ppa:opencpu/opencpu-2.2 && apt-get update && apt-get install -y opencpu-server
RUN apt-get update
# Install formr dependencies
RUN apt-get install -y build-essential gfortran libapparmor-dev libboost-all-dev
RUN apt-get install -y libcairo2-dev libcurl4-gnutls-dev libfontconfig1-dev
RUN apt-get install -y libgsl-dev libjpeg-dev liblapack-dev libmariadb-dev libpng-dev
RUN apt-get install -y libproj-dev libsodium-dev libssl-dev  libudunits2-dev libxml2-dev mesa-common-dev
RUN apt-get install -y libglu1-mesa-dev pandoc
RUN apt-get install -y libcurl4-openssl-dev libssl-dev
RUN apt-get install -y r-cran-devtools

# Install formr R package for opencpu
RUN Rscript -e "devtools::install_github('rubenarslan/formr', upgrade_dependencies = FALSE)"

# Expose the OpenCPU server port
EXPOSE 5656

# Start the OpenCPU server
CMD ["R", "-e", "library(opencpu); ocpu_start_server()"]
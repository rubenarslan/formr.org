# Dockerfile for formr.org
# Author: Elias-Leander Ahlers
# Date: 11.10.2023
# Derived from https://github.com/rubenarslan/formr.org/blob/master/INSTALLATION.md

FROM ubuntu:23.04

# Update apt repository
RUN apt update

# Install dependencies
RUN apt install -y git php apache2 mysql-server composer php-curl php-fpm php-mbstring php-mysql php-zip php-xml php-gd php-intl php-xml pandoc

#Composer error
ENV COMPOSER_ALLOW_SUPERUSER=1

# Enable apache2 rewrite module
RUN a2enmod rewrite

# Change apache config
RUN echo "<Directory /var/www/html>" >> /etc/apache2/sites-enabled/000-default.conf && \
    echo "    Options Indexes FollowSymlinks MultiViews" >> /etc/apache2/sites-enabled/000-default.conf && \
    echo "    AllowOverride All" >> /etc/apache2/sites-enabled/000-default.conf && \
    echo "    Order allow,deny" >> /etc/apache2/sites-enabled/000-default.conf && \
    echo "    allow from all" >> /etc/apache2/sites-enabled/000-default.conf && \
    echo "</Directory>" >> /etc/apache2/sites-enabled/000-default.conf

# Copy formr source into the container
# TODO: Think about another way to avoid a rebuild for changes to apply. Currently a volume 
# mount is problematic because we create a symlink to the webroot directory in the build step.
COPY . /var/www/formr.org/

# Create symbolic link to webroot
RUN ln -s /var/www/formr.org/webroot /var/www/html/formr

# Change owner of formr.org folder to www-data
RUN chown -R www-data:www-data /var/www/formr.org

# Install dependencies
RUN cd /var/www/formr.org && composer update && \
    composer install

# Duplicate config-dist folder to config folder
RUN cp -r /var/www/formr.org/config-dist /var/www/formr.org/config

# fixes the missing assets
RUN ln -s /var/www/formr.org/webroot /var/www/html/formr

#Add CRON
RUN apt-get install -y cron
RUN ln -s /var/www/formr.org/config/formr_crontab /etc/cron.d/formr

# Expose port 80 for apache2 server
EXPOSE 80

# Start apache2 and mysql service
CMD service apache2 start && service mysql start && systemctl enable cron && cron && sleep infinity

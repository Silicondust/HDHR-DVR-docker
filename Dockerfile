FROM alpine:3.24.4
WORKDIR /

# Basics
RUN echo "UTC" > /etc/timezone
RUN apk add --no-cache nginx supervisor wget grep curl sqlite shadow
# Create working directory
RUN mkdir -p /HDHomeRunDVR

#Install PHP 
# (opcache and json are built into PHP 8.5 core - no separate packages)
RUN apk add --no-cache php85 \
	php85-common \
	php85-fpm \
	php85-zip \
	php85-curl \
	php85-xml \
	php85-mbstring \
	php85-openssl \
	php85-sockets \
	php85-fileinfo \
	php85-dom \
	php85-pdo_sqlite

#configure defaults
RUN mkdir -p /HDHomeRunDVR/defaults
COPY config/* /HDHomeRunDVR/defaults/

#configure Supervisord
RUN mkdir -p /etc/supervisor.d/
COPY config/supervisord.conf /etc/supervisor.d/supervisord.conf

#Configure PHP
RUN mkdir -p /run/php
RUN touch /run/php/php-fpm85.pid

#Configure Nginx
RUN mkdir -p /run/nginx
RUN touch /run/nginx/nginx.pid
RUN ln -sf /dev/stdout /var/log/nginx/access.log
RUN ln -sf /dev/stderr /var/log/nginx/error.log
# Remove the Default
RUN rm -f /etc/nginx/http.d/default.conf

#Copy the UI
RUN mkdir -p /var/www/html/dvrui
COPY ui/ /var/www/html/dvrui/
RUN chown -R nginx:nginx /var/www/html/dvrui

# Create volume mount points
RUN mkdir /dvrdata
RUN mkdir /dvrrec
RUN ln -s /dvrdata /HDHomeRunDVR/data
RUN ln -s /dvrrec /HDHomeRunDVR/recordings

# Create the execution Environment
COPY scripts/hdhomerun.sh /HDHomeRunDVR
COPY scripts/supervisord.sh /HDHomeRunDVR
RUN chmod u+x /HDHomeRunDVR/hdhomerun.sh
RUN chmod 755 /HDHomeRunDVR/supervisord.sh

# Make sure we add VERSION
COPY VERSION /HDHomeRunDVR/VERSION

# Set Volumes to be added
VOLUME ["/dvrrec", "/dvrdata"]

# Optional PHP timezone for the UI (e.g. Europe/Lisbon), applied at container start - defaults to UTC
ENV DVRUI_TZ=""

# How much of the UI to run: none (no UI or web server), minimal (Dashboard and Diagnostics) or full - defaults to full
ENV DVRUI_ENABLE="full"

# Will use this port for mapping engine to the outside world
EXPOSE 59090

ENTRYPOINT ["/bin/sh","/HDHomeRunDVR/supervisord.sh"]

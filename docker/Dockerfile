FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive
ENV container=docker

RUN apt-get update \
    && apt-get install -y systemd systemd-sysv dbus sudo ca-certificates curl gnupg \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* \
    && find /lib/systemd/system/sysinit.target.wants -mindepth 1 -maxdepth 1 ! -name systemd-tmpfiles-setup.service -delete \
    && rm -f /lib/systemd/system/multi-user.target.wants/* \
    && rm -f /etc/systemd/system/*.wants/* \
    && rm -f /lib/systemd/system/local-fs.target.wants/* \
    && rm -f /lib/systemd/system/sockets.target.wants/*udev* \
    && rm -f /lib/systemd/system/sockets.target.wants/*initctl* \
    && rm -f /lib/systemd/system/basic.target.wants/*

# Keep package installs from starting daemons while the image is built.
RUN printf '#!/bin/sh\nexit 101\n' > /usr/sbin/policy-rc.d && chmod +x /usr/sbin/policy-rc.d

COPY scripts /opt/laravelify/scripts
RUN install -d /opt/laravelify/storage /opt/laravelify/bootstrap/cache \
    && sed -i 's/\r$//' /opt/laravelify/scripts/*.sh \
    && bash /opt/laravelify/scripts/install-server.sh \
    && rm -f /usr/sbin/policy-rc.d

COPY . /opt/laravelify
WORKDIR /opt/laravelify
RUN sed -i 's/\r$//' scripts/*.sh \
    && install -d -o www-data -g www-data \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && composer install --no-interaction --prefer-dist \
    && npm install \
    && npm run build \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/bootstrap.sh /usr/local/bin/laravelify-bootstrap.sh
COPY docker/laravelify-boot.service /etc/systemd/system/laravelify-boot.service
COPY docker/laravelify-panel.service /etc/systemd/system/laravelify-panel.service
RUN sed -i 's/\r$//' /usr/local/bin/laravelify-bootstrap.sh \
    && chmod 755 /usr/local/bin/laravelify-bootstrap.sh \
    && systemctl enable laravelify-boot.service laravelify-panel.service

STOPSIGNAL SIGRTMIN+3
CMD ["/sbin/init"]

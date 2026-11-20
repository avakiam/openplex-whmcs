FROM php:8.2-apache
RUN apt-get update -qq && apt-get install -y -qq --no-install-recommends \
      libpng-dev libjpeg-dev libfreetype6-dev libzip-dev libicu-dev libgmp-dev libxml2-dev curl ca-certificates \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd pdo_mysql mysqli zip intl bcmath gmp soap \
    && rm -rf /var/lib/apt/lists/*
# ionCube Loader (WHMCS is encoded with it)
RUN curl -fsSL https://downloads.ioncube.com/loader_downloads/ioncube_loaders_lin_x86-64.tar.gz -o /tmp/ioncube.tgz \
    && tar -xzf /tmp/ioncube.tgz -C /tmp \
    && ext=$(php -r 'echo ini_get("extension_dir");') \
    && cp /tmp/ioncube/ioncube_loader_lin_8.2.so "$ext/" \
    && echo "zend_extension=ioncube_loader_lin_8.2.so" > /usr/local/etc/php/conf.d/00-ioncube.ini \
    && rm -rf /tmp/ioncube /tmp/ioncube.tgz
RUN printf 'memory_limit=256M\nmax_execution_time=300\ndate.timezone=UTC\n' > /usr/local/etc/php/conf.d/whmcs.ini \
    && a2enmod rewrite

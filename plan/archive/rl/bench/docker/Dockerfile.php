FROM php:8.5-cli
RUN apt-get update && apt-get install -y libonig-dev libzip-dev unzip git libffi-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev mariadb-client && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install ffi pdo_mysql mbstring zip exif pcntl bcmath && docker-php-ext-configure gd --with-freetype=/usr/include/ --with-jpeg=/usr/include/ && docker-php-ext-install gd
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
RUN echo 'memory_limit=4G' > /usr/local/etc/php/conf.d/mem.ini && echo 'ffi.enable=true' > /usr/local/etc/php/conf.d/ffi.ini

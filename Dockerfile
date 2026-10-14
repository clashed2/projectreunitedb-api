FROM php:8.2-apache
# Enable PDO and MySQL extensions for database interactions
RUN docker-php-ext-install pdo pdo_mysql
# Enable URL rewriting
RUN a2enmod rewrite
# Copy your PHP scripts to the server directory
COPY . /var/www/html/
EXPOSE 80
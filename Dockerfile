FROM php:8.2-apache 

# Enable PDO and MySQL extensions for database interactions 
RUN docker-php-ext-install pdo pdo_mysql 

# Enable URL rewriting 
RUN a2enmod rewrite 

# Copy project files to server directory 
COPY . /var/www/html/ 

# Create uploads folder and grant write permissions to Apache www-data
RUN mkdir -p /var/www/html/uploads && \
    chown -R www-data:www-data /var/www/html && \
    chmod -R 777 /var/www/html/uploads

EXPOSE 80
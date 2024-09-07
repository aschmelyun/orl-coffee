CREATE DATABASE IF NOT EXISTS coffeedb;
USE coffeedb;

-- Create the coffee_shops table
CREATE TABLE IF NOT EXISTS shops (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    location VARCHAR(255),
    hours_open VARCHAR(50),
    drink_types TEXT,
    food_available BOOLEAN,
    rating TINYINT,
    image VARCHAR(255)
);

-- Create a table for comments
CREATE TABLE IF NOT EXISTS comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shop_id INT,
    FOREIGN KEY (shop_id) REFERENCES shops(id),
    name VARCHAR(60),
    body TEXT
);

-- Create the admins table
CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL
);

-- Grant privileges to the 'coffee' user
GRANT ALL PRIVILEGES ON coffeedb.* TO 'coffee'@'%';
FLUSH PRIVILEGES;
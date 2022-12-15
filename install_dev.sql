CREATE DATABASE excimer;
CREATE USER 'excimer'@'localhost' IDENTIFIED BY 'devpassword';
GRANT ALL PRIVILEGES ON excimer.* TO 'excimer'@'localhost' WITH GRANT OPTION;

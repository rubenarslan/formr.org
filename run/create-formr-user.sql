-- The Database 'formr' has to be initialized before performing this script.
CREATE USER 'formr'@'%' IDENTIFIED BY '<$DB_PASSWORD>';
GRANT REFERENCES, SELECT, INSERT, UPDATE, DELETE, CREATE, INDEX, DROP, ALTER, CREATE TEMPORARY TABLES, LOCK TABLES ON blog.* TO 'formr'@'%';
GRANT ALL PRIVILEGES ON formr.* TO 'formr'@'%';
FLUSH PRIVILEGES;

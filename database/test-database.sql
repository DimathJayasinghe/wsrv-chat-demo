-- A second database for the tests, so running them never touches your chat data.
CREATE DATABASE IF NOT EXISTS chat_test DEFAULT CHARACTER SET utf8mb4;
GRANT ALL PRIVILEGES ON chat_test.* TO 'chat'@'%';
USE chat_test;
SOURCE /docker-entrypoint-initdb.d/10-schema.sql;

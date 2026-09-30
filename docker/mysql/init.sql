-- Runs only on first initialization of the named volume (docker-entrypoint-initdb.d).
-- feedreader_test is what Doctrine's dbname_suffix derives from the dev DATABASE_URL, and
-- the grant pattern also covers each ParaTest worker's feedreader_test<N>.
CREATE DATABASE IF NOT EXISTS feedreader_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
GRANT ALL PRIVILEGES ON `feedreader\_test%`.* TO 'feedreader'@'%';
FLUSH PRIVILEGES;

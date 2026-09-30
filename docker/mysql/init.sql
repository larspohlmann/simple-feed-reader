-- Runs only on first initialization of the named volume (docker-entrypoint-initdb.d).
-- The test database mirrors what Doctrine's dbname_suffix ('_test' in when@test)
-- derives from the dev DATABASE_URL, so one URL serves both dev and phpunit.
-- The pattern also covers each ParaTest worker's feedreader_test<N> database.
CREATE DATABASE IF NOT EXISTS feedreader_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
GRANT ALL PRIVILEGES ON `feedreader\_test%`.* TO 'feedreader'@'%';
FLUSH PRIVILEGES;

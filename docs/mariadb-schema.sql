-- Local throwaway MariaDB/InnoDB test schema. No production database is touched.
CREATE TABLE IF NOT EXISTS capacity_pools (
    pool_id VARCHAR(80) NOT NULL PRIMARY KEY
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS capacity_intervals (
    interval_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    pool_id VARCHAR(80) NOT NULL,
    starts_at BIGINT NOT NULL,
    ends_at BIGINT NOT NULL,
    units INT NOT NULL,
    CONSTRAINT capacity_intervals_pool FOREIGN KEY (pool_id) REFERENCES capacity_pools(pool_id),
    INDEX idx_capacity_interval_pool_time (pool_id, ends_at, starts_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS capacity_bookings (
    booking_id VARCHAR(80) NOT NULL PRIMARY KEY,
    pool_id VARCHAR(80) NOT NULL,
    starts_at BIGINT NOT NULL,
    ends_at BIGINT NOT NULL,
    units INT NOT NULL,
    CONSTRAINT capacity_bookings_pool FOREIGN KEY (pool_id) REFERENCES capacity_pools(pool_id),
    INDEX idx_capacity_bookings_pool_time (pool_id, ends_at, starts_at)
) ENGINE=InnoDB;

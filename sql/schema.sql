CREATE TABLE IF NOT EXISTS availability_slots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  start_time DATETIME NOT NULL,
  end_time DATETIME NOT NULL,
  price INT UNSIGNED NOT NULL,
  status ENUM('available', 'pending', 'booked') NOT NULL DEFAULT 'available',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_availability_slots_status_start (status, start_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bookings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slot_id BIGINT UNSIGNED NOT NULL,
  customer_name VARCHAR(120) NOT NULL,
  customer_email VARCHAR(255) NOT NULL,
  customer_phone VARCHAR(40) NOT NULL,
  stripe_session_id VARCHAR(255) NULL UNIQUE,
  payment_status ENUM('pending', 'paid', 'failed') NOT NULL DEFAULT 'pending',
  booking_status ENUM('pending', 'confirmed', 'cancelled') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_bookings_slot_id (slot_id),
  CONSTRAINT fk_bookings_slot_id
    FOREIGN KEY (slot_id) REFERENCES availability_slots(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================
--  CLEAN MINIMAL SCHEMA FOR THE 442 POKER PROJECT
--  Only the tables actually used by the live code
--  Snapshot-based architecture (single source of truth)
-- ================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ================================
-- USERS + AUTH
-- ================================
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  verified_at DATETIME NULL,
  last_login_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL COMMENT 'NULL allows temporary sessions (e.g., for CSRF)',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  ip_hash CHAR(64) NOT NULL,
  user_agent VARCHAR(255) NOT NULL,
  refresh_token CHAR(64) UNIQUE,
  revoked_at DATETIME NULL,
  INDEX (user_id),
  INDEX (expires_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE csrf_nonces (
  id INT AUTO_INCREMENT PRIMARY KEY,
  session_id INT NOT NULL,
  nonce CHAR(64) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  INDEX (session_id, used_at),
  FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token CHAR(64) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  INDEX (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================
-- LOBBY PRESENCE & CHALLENGES
-- ================================
CREATE TABLE user_lobby_presence (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL UNIQUE,
  user_username VARCHAR(50) NOT NULL,
  status ENUM('online','in_game','idle') NOT NULL DEFAULT 'online',
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (last_seen_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE game_challenges (
  id INT AUTO_INCREMENT PRIMARY KEY,
  from_user_id INT NOT NULL,
  to_user_id INT NOT NULL,
  status ENUM('pending','accepted','declined','expired') NOT NULL DEFAULT 'pending',
  game_id INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  responded_at DATETIME NULL,
  expires_at DATETIME NULL,
  INDEX (from_user_id),
  INDEX (to_user_id),
  INDEX (status),
  FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (to_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ================================
-- TABLES + SEATS
-- ================================
CREATE TABLE `tables` (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  max_seats TINYINT NOT NULL DEFAULT 6,
  small_blind INT NOT NULL,
  big_blind INT NOT NULL,
  ante INT NOT NULL DEFAULT 0,
  status ENUM('OPEN','IN_GAME','CLOSED') DEFAULT 'OPEN',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (status),
  INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE table_seats (
  id INT AUTO_INCREMENT PRIMARY KEY,
  table_id INT NOT NULL,
  seat_no TINYINT NOT NULL,
  user_id INT NULL,
  joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  left_at TIMESTAMP NULL,
  UNIQUE KEY (table_id, seat_no),
  FOREIGN KEY (table_id) REFERENCES `tables`(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX (table_id),
  INDEX (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================
-- GAME (ONE HAND = ONE GAME)
-- ================================
CREATE TABLE games (
  id INT AUTO_INCREMENT PRIMARY KEY,
  table_id INT NOT NULL,
  dealer_seat TINYINT,
  sb_seat TINYINT,
  bb_seat TINYINT,
  deck_seed INT,
  version INT DEFAULT 0,
  started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  ended_at TIMESTAMP NULL,
  status ENUM('ACTIVE','COMPLETE') DEFAULT 'ACTIVE',
  FOREIGN KEY (table_id) REFERENCES `tables`(id) ON DELETE CASCADE,
  INDEX (table_id),
  INDEX (status),
  INDEX (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================
-- SNAPSHOTS (SINGLE SOURCE OF TRUTH)
-- ================================
CREATE TABLE game_snapshots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  game_id INT NOT NULL,
  version INT NOT NULL,
  state_json JSON NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY (game_id, version),
  INDEX (game_id),
  INDEX (game_id, version),
  FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================
-- CHAT + MESSAGING
-- ================================
CREATE TABLE chat_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  channel_type ENUM('lobby','game') NOT NULL,
  channel_id INT NOT NULL,
  sender_user_id INT NOT NULL,
  sender_username VARCHAR(50) NOT NULL,
  recipient_user_id INT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (channel_type, channel_id, created_at),
  INDEX (sender_user_id),
  FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CHECK (
    (channel_type='lobby' AND channel_id=0)
    OR (channel_type='game' AND channel_id>0)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================
-- WEBSOCKET SUBSCRIPTIONS
-- ================================
CREATE TABLE ws_subscriptions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  connection_id CHAR(64) NOT NULL UNIQUE,
  channel_type ENUM('lobby','game') NOT NULL,
  channel_id INT NOT NULL,
  game_id INT NULL,
  last_action_id_seen INT NULL,
  last_chat_id_seen INT NULL,
  connected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_ping_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  disconnected_at DATETIME NULL,
  INDEX (user_id),
  INDEX (channel_type, channel_id),
  INDEX (last_ping_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
  FOREIGN KEY (last_chat_id_seen) REFERENCES chat_messages(id) ON DELETE SET NULL,
  CHECK (
    (channel_type='lobby' AND channel_id=0 AND game_id IS NULL)
    OR (channel_type='game' AND channel_id=game_id)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================
-- AUDIT LOGGING
-- ================================
CREATE TABLE audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id INT NULL,
  session_id INT NULL,
  ip_address VARCHAR(45) NULL,
  ip_hash CHAR(64) NULL,
  user_agent VARCHAR(500) NULL,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(50) NULL,
  entity_id INT NULL,
  details JSON NULL,
  channel ENUM('api','websocket') NOT NULL DEFAULT 'api',
  status ENUM('success','failure','error') NOT NULL DEFAULT 'success',
  severity ENUM('info','warn','error','critical') NOT NULL DEFAULT 'info',
  previous_hash CHAR(64) NULL,
  INDEX idx_user_id (user_id),
  INDEX idx_session_id (session_id),
  INDEX idx_timestamp (timestamp),
  INDEX idx_action (action),
  INDEX idx_entity (entity_type, entity_id),
  INDEX idx_channel (channel),
  INDEX idx_status (status),
  INDEX idx_severity (severity),
  INDEX idx_user_timestamp (user_id, timestamp),
  INDEX idx_action_timestamp (action, timestamp),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

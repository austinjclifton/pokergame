-- ================================
--  FULL MYSQL SCHEMA FOR POKER GAME
--  Compatible with local + RLES server
-- ================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

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
  user_id INT NULL COMMENT 'NULL for temporary sessions (e.g., unauthenticated users getting CSRF nonces)',
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

CREATE TABLE user_lobby_presence (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL UNIQUE,
  user_username VARCHAR(50) NOT NULL,
  status ENUM('online','in_game','idle') NOT NULL DEFAULT 'online',
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (last_seen_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE games (
  id INT AUTO_INCREMENT PRIMARY KEY,
  status ENUM('waiting','active','finished') NOT NULL DEFAULT 'waiting',
  small_blind INT UNSIGNED NOT NULL,
  big_blind INT UNSIGNED NOT NULL,
  starting_stack INT UNSIGNED NOT NULL,
  turn_timer_secs INT UNSIGNED NOT NULL DEFAULT 30,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (status)
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

CREATE TABLE game_players (
  game_id INT NOT NULL,
  user_id INT NOT NULL,
  seat TINYINT NOT NULL,
  stack INT UNSIGNED NOT NULL,
  joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  left_at DATETIME NULL,
  role ENUM('sb','bb','button','none') NULL,
  status ENUM('active','folded','all_in','left') NOT NULL DEFAULT 'active',
  PRIMARY KEY (game_id, user_id),
  UNIQUE KEY uq_seat (game_id, seat),
  INDEX (user_id),
  FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CHECK (seat IN (1,2))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE game_hands (
  id INT AUTO_INCREMENT PRIMARY KEY,
  game_id INT NOT NULL,
  hand_no INT NOT NULL,
  street ENUM('preflop','flop','turn','river','showdown') NOT NULL DEFAULT 'preflop',
  dealer_user_id INT NOT NULL,
  active_player_id INT NOT NULL,
  act_deadline_at DATETIME NULL,
  board_cards VARCHAR(64) NULL,
  deck_seed VARCHAR(64) NULL,
  deck_hash CHAR(64) NULL,
  winner_user_id INT NULL,
  win_amount INT UNSIGNED NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_at DATETIME NULL,
  UNIQUE KEY uq_game_hand (game_id, hand_no),
  INDEX (game_id, hand_no),
  FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
  FOREIGN KEY (dealer_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (active_player_id) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (winner_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE actions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  hand_id INT NOT NULL,
  game_id INT NOT NULL,
  user_id INT NOT NULL,
  hand_no INT NOT NULL,
  seq_no INT NOT NULL,
  street ENUM('preflop','flop','turn','river','showdown') NOT NULL,
  action_type ENUM('post_sb','post_bb','post_ante','check','call','bet','raise','allin','fold','timeout_fold') NOT NULL,
  amount INT UNSIGNED NOT NULL DEFAULT 0,
  balance_before INT NOT NULL,
  balance_after INT NOT NULL,
  action_nonce CHAR(64) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hand_seq (hand_id, seq_no),
  INDEX (game_id),
  INDEX (user_id),
  INDEX (game_id, hand_no),
  FOREIGN KEY (hand_id) REFERENCES game_hands(id) ON DELETE CASCADE,
  FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE game_state_snapshots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  game_id INT NOT NULL,
  hand_id INT NULL,
  hand_no INT NULL,
  street VARCHAR(20) NULL,
  snapshot_type VARCHAR(50) NOT NULL,
  snapshot_reason VARCHAR(100) NULL,
  state_json JSON NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (game_id),
  INDEX (hand_id),
  INDEX (game_id, hand_no),
  FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
  FOREIGN KEY (hand_id) REFERENCES game_hands(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
  FOREIGN KEY (last_action_id_seen) REFERENCES actions(id) ON DELETE SET NULL,
  FOREIGN KEY (last_chat_id_seen) REFERENCES chat_messages(id) ON DELETE SET NULL,
  CHECK ((channel_type='lobby' AND channel_id=0 AND game_id IS NULL) OR (channel_type='game' AND channel_id=game_id))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id INT NULL,
  session_id INT NULL,
  ip_address VARCHAR(45) NULL COMMENT 'IPv4 or IPv6 address (hashed in ip_hash)',
  ip_hash CHAR(64) NULL COMMENT 'SHA-256 hash of IP address for privacy',
  user_agent VARCHAR(500) NULL,
  action VARCHAR(100) NOT NULL COMMENT 'Action performed (e.g., user.login, challenge.create)',
  entity_type VARCHAR(50) NULL COMMENT 'Type of entity affected (e.g., user, challenge, game)',
  entity_id INT NULL COMMENT 'ID of the affected entity',
  details JSON NULL COMMENT 'Additional structured data about the event',
  channel ENUM('api','websocket') NOT NULL DEFAULT 'api' COMMENT 'Channel where action occurred',
  status ENUM('success','failure','error') NOT NULL DEFAULT 'success' COMMENT 'Outcome of the action',
  severity ENUM('info','warn','error','critical') NOT NULL DEFAULT 'info',
  previous_hash CHAR(64) NULL COMMENT 'Hash of previous audit log entry (for tamper detection)',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
COMMENT='Audit trail for all significant actions. Append-only design for tamper resistance.';

CREATE TABLE hand_players (
  hand_id INT NOT NULL,
  user_id INT NOT NULL,
  seat TINYINT NOT NULL,
  card_a CHAR(2) NULL,
  card_b CHAR(2) NULL,
  stack_start INT UNSIGNED NOT NULL,
  stack_end INT UNSIGNED NULL,
  received_at DATETIME NULL,
  folded_at DATETIME NULL,
  all_in_at DATETIME NULL,
  exposed_at DATETIME NULL,
  final_hand_rank SMALLINT NULL,
  final_hand_desc VARCHAR(50) NULL,
  PRIMARY KEY (hand_id, user_id),
  UNIQUE KEY uq_seat (hand_id, seat),
  INDEX (user_id),
  FOREIGN KEY (hand_id) REFERENCES game_hands(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CHECK (seat IN (1,2))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_game_results (
  user_id INT NOT NULL,
  game_id INT NOT NULL,
  result ENUM('win','loss','draw','abandoned') NOT NULL,
  chips_delta INT NOT NULL,
  ended_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, game_id),
  INDEX (game_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

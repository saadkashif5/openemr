CREATE TABLE IF NOT EXISTS `platform_approval` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `correlation_id` char(36) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `proposed_by_type` varchar(8) NOT NULL,
  `proposed_by_user_id` bigint(20) NOT NULL,
  `proposed_by_client_id` varchar(80) NOT NULL DEFAULT '',
  `action_type` varchar(80) NOT NULL,
  `resource` varchar(255) NOT NULL DEFAULT '',
  `patient_id` bigint(20) DEFAULT NULL,
  `payload` longtext NOT NULL,
  `created_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `decided_by_user_id` bigint(20) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `decision_note` varchar(255) DEFAULT NULL,
  `executed_at` datetime DEFAULT NULL,
  `result` longtext,
  PRIMARY KEY (`id`),
  KEY `status_expires` (`status`, `expires_at`),
  KEY `correlation` (`correlation_id`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `platform_setting` (
  `name` varchar(80) NOT NULL,
  `value` varchar(255) NOT NULL DEFAULT '',
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB;

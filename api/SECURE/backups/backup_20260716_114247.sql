-- Backup of enflowCentralServer — 2026-07-16T11:42:47+00:00

SET FOREIGN_KEY_CHECKS=0;

-- Table: enflow_settings
DROP TABLE IF EXISTS `enflow_settings`;
CREATE TABLE `enflow_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4;

INSERT INTO `enflow_settings` VALUES('1','trial_days','10');

-- Table: subscriptions
DROP TABLE IF EXISTS `subscriptions`;
CREATE TABLE `subscriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fullname` varchar(100) DEFAULT NULL,
  `username` varchar(100) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `email_status` varchar(20) DEFAULT 'pending',
  `email_otp` varchar(6) DEFAULT NULL,
  `email_otp_expires` datetime DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `currency` varchar(10) DEFAULT NULL,
  `num_locations` int(11) DEFAULT NULL,
  `num_staff` int(11) DEFAULT NULL,
  `logo_url` varchar(500) DEFAULT NULL,
  `connected_tools` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`connected_tools`)),
  `team_members` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`team_members`)),
  `zara_brand_voice` varchar(100) DEFAULT NULL,
  `zara_primary_lang` varchar(50) DEFAULT NULL,
  `zara_also_speaks` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`zara_also_speaks`)),
  `zara_top_goals` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`zara_top_goals`)),
  `zara_hours` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`zara_hours`)),
  `onboarding_step` int(11) DEFAULT 0,
  `dob` date DEFAULT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `business_type` varchar(100) DEFAULT NULL,
  `business_subtype` varchar(100) DEFAULT NULL,
  `business_name` varchar(150) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `plan` varchar(200) DEFAULT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `transaction_id` varchar(200) DEFAULT NULL,
  `status` enum('trial','active','suspended','expired','cancelled') DEFAULT NULL,
  `renewal_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `subscription_code` varchar(21) NOT NULL,
  `zara_credits` int(11) DEFAULT 0,
  `trial_started_at` datetime DEFAULT NULL,
  `trial_ends_at` datetime DEFAULT NULL,
  `onboarding_token` varchar(64) DEFAULT NULL,
  `zara_credits_used` int(11) DEFAULT 0,
  `low_credit_alert_sent` tinyint(1) DEFAULT 0,
  `auth_token` varchar(64) DEFAULT NULL,
  `auth_token_expiry` datetime DEFAULT NULL,
  `local_server_url` varchar(255) DEFAULT NULL,
  `software_url` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subscription_code` (`subscription_code`),
  KEY `idx_sub_email` (`email`),
  KEY `idx_sub_phone` (`phone`)
) ENGINE=InnoDB AUTO_INCREMENT=67 DEFAULT CHARSET=utf8mb4;

INSERT INTO `subscriptions` VALUES('61','Hhtty','Ki','Wsamson630@gmail.com','+234 9021884679','$2y$10$Hcq4/mk47YwRg1JOEe.wF.VLrWr6SREu/6DfPYhHfb/V12i9VGkXW','verified',NULL,NULL,'Nigeria','NGN','1','7','https://res.cloudinary.com/daw8cr3tv/image/upload/v1783445195/logo_TRIAL-B80621BC75.png','{\"pos\": [\"square\", \"toast\", \"moniepoint\"], \"social\": [\"connected\"], \"delivery\": [\"chowdeck\", \"glovo\"], \"whatsapp\": true, \"accounting\": [\"quickbooks\"], \"google_reviews\": false}','[{\"role\": \"waiter\", \"email\": \"dammian@gmail.com\"}]','professional','English','[\"Pidgin\"]','[\"reduce_wait_time\"]','[{\"day\": \"wednesday\", \"hours\": \"10am–11pm\"}, {\"day\": \"thursday\", \"hours\": \"10am–11pm\"}, {\"day\": \"friday\", \"hours\": \"10am–11pm\"}, {\"day\": \"saturday\", \"hours\": \"10am–11pm\"}, {\"day\": \"sunday\", \"hours\": \"10am–11pm\"}]','9','2026-07-16','Male','Cloud Kitchen','[\"delivery\",\"multi_branch\",\"cloud_kitchen\"]','Jkk','www.ccjitters.com','Web + Zara','89000.00','10375949','active','2026-11-16','2026-07-07 17:23:10','SUB-B63612C4A2','1100','2026-07-07 17:23:10','2026-07-07 17:23:10',NULL,'0','0','d82d581155f6b1dc866de38a63c1f0a6f5ca28ef0ef50a5376568d0a973c901f','2026-07-07 18:37:53','http://localhost:8080/saas-enflowApi',NULL);
INSERT INTO `subscriptions` VALUES('66','Hhtty','Kl','Tristincassey@gmail.com','+234 8096831043','$2y$10$DaoSlI7Ee8AVKGv3WbAphOJfEpudvHkRWb49Il91BobXf4B3u9qSO','verified',NULL,NULL,'Nigeria','NGN','3','7','https://res.cloudinary.com/daw8cr3tv/image/upload/v1784153132/logo_TRIAL-41A402A123.png','{\"pos\":[\"moniepoint\"],\"whatsapp\":true,\"social\":[\"instagram\",\"facebook\"],\"google_reviews\":true,\"delivery\":[\"chowdeck\"],\"accounting\":[\"zoho\"]}','[{\"email\":\"wsamson630@gmail.com\",\"role\":\"owner\"}]','professional','English','[\"Pidgin\"]','[\"increase_orders\"]','[{\"day\":\"thursday\",\"hours\":\"9am\\u20139pm\"},{\"day\":\"friday\",\"hours\":\"9am\\u20139pm\"},{\"day\":\"saturday\",\"hours\":\"9am\\u20139pm\"},{\"day\":\"sunday\",\"hours\":\"9am\\u20139pm\"}]','9','2026-07-16','Male','Cafe','[\"dine_in\",\"takeaway\",\"delivery\"]','Jkk','jkk.com','Web + Zara','89000.00','10375953','active','2026-09-14','2026-07-15 22:03:33','SUB-E449567302','5600','2026-07-15 22:03:33','2026-07-15 22:03:33',NULL,'568','0','3c087a7fb3693db66fdaa875091a72e48b5e71469488ea55ae433b7cd083302b','2026-07-16 06:04:18','http://localhost:8080/saas-enflowApi','https://the-arinas-spot.getenflow.online');

-- Table: zara_topup_logs
DROP TABLE IF EXISTS `zara_topup_logs`;
CREATE TABLE `zara_topup_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(150) DEFAULT NULL,
  `transaction_id` varchar(200) DEFAULT NULL,
  `pack_id` varchar(50) DEFAULT NULL,
  `credits` int(11) DEFAULT NULL,
  `amount` decimal(12,2) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `transaction_id` (`transaction_id`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4;

INSERT INTO `zara_topup_logs` VALUES('15','vickynaldo12345@gmail.com','10318142','starter','500','52250.00','2026-06-22 20:24:18');
INSERT INTO `zara_topup_logs` VALUES('16','Wsamson630@gmail.com','10331387','basic','1000','101200.00','2026-06-28 14:03:34');
INSERT INTO `zara_topup_logs` VALUES('17','Wsamson630@gmail.com','10331481','basic','1000','101200.00','2026-06-28 15:16:13');
INSERT INTO `zara_topup_logs` VALUES('18','Wsamson630@gmail.com','10345817','starter','500','52250.00','2026-07-03 19:47:55');
INSERT INTO `zara_topup_logs` VALUES('19','Tristincassey@gmail.com','10376586','basic','1000','101200.00','2026-07-16 11:58:30');
INSERT INTO `zara_topup_logs` VALUES('20','Tristincassey@gmail.com','10376597','popular','3000','280500.00','2026-07-16 12:00:54');
INSERT INTO `zara_topup_logs` VALUES('21','Tristincassey@gmail.com','10376627','starter','500','52250.00','2026-07-16 12:15:57');

SET FOREIGN_KEY_CHECKS=1;

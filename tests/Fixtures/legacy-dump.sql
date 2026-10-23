-- MariaDB dump 10.19  Distrib 10.6.7-MariaDB, for Linux (x86_64)
-- A few rows shaped like the old site's dump, for ImportLegacyCommandTest.
/*!40101 SET NAMES utf8 */;
/*!40103 SET TIME_ZONE='+00:00' */;

DROP TABLE IF EXISTS `hash_table`;
CREATE TABLE `hash_table` (
  `hash_id` int(11) NOT NULL AUTO_INCREMENT,
  `hash` varchar(60) DEFAULT NULL,
  `used` tinyint(1) DEFAULT 0,
  `time` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  UNIQUE KEY `hash_id` (`hash_id`)
) ENGINE=MyISAM AUTO_INCREMENT=3 DEFAULT CHARSET=latin1;

LOCK TABLES `hash_table` WRITE;
INSERT INTO `hash_table` VALUES (1,'df8add665872cc58cbc37c77397dece6',1,1503783572,10),(2,'128ac83a00ee25a234438a13f2e2d260',0,1509007026,30);
UNLOCK TABLES;

DROP TABLE IF EXISTS `launch_table`;
CREATE TABLE `launch_table` (
  `listing_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `title` varchar(256) NOT NULL DEFAULT '',
  `author` varchar(164) NOT NULL DEFAULT '',
  `dept` varchar(11) DEFAULT NULL,
  `price` int(4) NOT NULL,
  `for_sale` varchar(1) NOT NULL DEFAULT 'y',
  `year` int(4) NOT NULL,
  `edition` int(5) NOT NULL,
  `papers` varchar(128) NOT NULL DEFAULT '',
  `comments` text NOT NULL,
  `quality` int(1) NOT NULL,
  `server_date` varchar(30) DEFAULT NULL,
  `number_visible` varchar(1) NOT NULL,
  `date_added` varchar(24) DEFAULT NULL,
  `date_removed` varchar(24) DEFAULT NULL,
  `date_refreshed` varchar(24) DEFAULT NULL,
  `email_reminder` varchar(1) DEFAULT NULL,
  `email_expired` varchar(1) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`listing_id`),
  KEY `user_id` (`user_id`),
  KEY `listing_id` (`listing_id`)
) ENGINE=MyISAM AUTO_INCREMENT=106 DEFAULT CHARSET=latin1;

LOCK TABLES `launch_table` WRITE;
INSERT INTO `launch_table` VALUES (100,10,'Principles of Anatomy and Physiology','Tortora & Derrickson','sci',120,'n',2012,13,'HUBS191 & HUBS192','Don\\\'t get caught up in the pre-exam library rush!\r\nHardly used.',5,'2011-03-14 17:50:00','y','1300078200','1301000000',NULL,NULL,NULL,NULL,NULL),(101,11,'Principles of Anatomy and Physiology','Tortora & Derrickson','sci',90,'n',2012,13,'HUBS 191, 192','Some (light) highlighting, \'tidy\'',3,'','n','1310000000','',NULL,NULL,NULL,NULL,NULL);
INSERT INTO `launch_table` VALUES (102,11,'Writing for Psychology','O\\\\\\\'Shea, Moss','arts',0,'y',2010,1,'Psych111','An introduction to MÄori culture.',1,'','y','1700000000','','1760000000',NULL,NULL,NULL,NULL),(103,99,'Media and Society','O\\\'Shaughnessy','arts',40,'n',2008,22,'MFCO102','',2,'','n','1300000000','',NULL,NULL,NULL,NULL,NULL),(104,20,'Campbell Biology','Campbell & Reece','sci',60,'y',2014,10,'BIOL112','',4,'','y','1700000000','',NULL,NULL,NULL,NULL,NULL),(105,30,'Principles of Anatomy & Physiology (13th ed.)','G. Tortora, Derrickson','sci',100,'y',2013,0,'','',4,'','n','1320000000','',NULL,NULL,NULL,NULL,NULL);
UNLOCK TABLES;

DROP TABLE IF EXISTS `member_list`;
CREATE TABLE `member_list` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `pass` varchar(40) NOT NULL,
  `first_name` varchar(64) NOT NULL DEFAULT '',
  `email` varchar(64) NOT NULL,
  `phone` varchar(11) DEFAULT NULL,
  `join_date` timestamp NULL DEFAULT NULL,
  `access` varchar(11) DEFAULT NULL,
  `last_login` int(11) DEFAULT NULL,
  `join_time` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `deactivated` int(11) DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  KEY `user_id` (`user_id`,`first_name`),
  KEY `email` (`email`)
) ENGINE=MyISAM AUTO_INCREMENT=31 DEFAULT CHARSET=latin1;

LOCK TABLES `member_list` WRITE;
INSERT INTO `member_list` VALUES (10,'89e338106a1ae90c3b004b1bac0ea67abc22aab4','Jo','Jo@Example.com','021 555 0100','2011-03-14 23:51:43',NULL,1300000000,NULL,NULL,NULL),(11,'F3BBBD66A63D4BF1747940578EC3D0103530E21D','Jo B','jo@example.com ','0215550101','2012-01-01 00:00:00',NULL,1400000000,NULL,NULL,NULL),(20,'f07c541dfb9d889f96e2c42a7900b5af9a65c099','AÃ¯cha','aicha@example.com','021MyName','2013-05-05 10:00:00','1',NULL,NULL,NULL,NULL),(30,'76272dc4faf660733711f58c736830d27159fb55','Test','test@test',NULL,NULL,NULL,NULL,NULL,NULL,NULL);
UNLOCK TABLES;

-- MySQL dump 10.13  Distrib 8.0.45, for Linux (x86_64)
--
-- Host: localhost    Database: delta_rb18
-- ------------------------------------------------------
-- Server version	8.0.45-0ubuntu0.22.04.1

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `admins`
--

DROP TABLE IF EXISTS `admins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `admins` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(200) NOT NULL,
  `password` varchar(200) NOT NULL,
  `backupchannel` varchar(200) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `lang` varchar(10) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admins`
--

LOCK TABLES `admins` WRITE;
/*!40000 ALTER TABLE `admins` DISABLE KEYS */;
INSERT INTO `admins` VALUES (1,'s3EL6kNQcX#fuxB','Pd8mJXcLCzul0NY','-1002545458541','en');
/*!40000 ALTER TABLE `admins` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `black_list`
--

DROP TABLE IF EXISTS `black_list`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `black_list` (
  `id` int NOT NULL AUTO_INCREMENT,
  `info` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `black_list`
--

LOCK TABLES `black_list` WRITE;
/*!40000 ALTER TABLE `black_list` DISABLE KEYS */;
/*!40000 ALTER TABLE `black_list` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chats`
--

DROP TABLE IF EXISTS `chats`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chats` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` bigint NOT NULL,
  `create_date` int NOT NULL,
  `title` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `category` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `state` int NOT NULL,
  `rate` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chats`
--

LOCK TABLES `chats` WRITE;
/*!40000 ALTER TABLE `chats` DISABLE KEYS */;
/*!40000 ALTER TABLE `chats` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `chats_info`
--

DROP TABLE IF EXISTS `chats_info`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chats_info` (
  `id` int NOT NULL AUTO_INCREMENT,
  `chat_id` int NOT NULL,
  `sent_date` int NOT NULL,
  `msg_type` varchar(50) DEFAULT NULL,
  `text` varchar(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `chats_info`
--

LOCK TABLES `chats_info` WRITE;
/*!40000 ALTER TABLE `chats_info` DISABLE KEYS */;
/*!40000 ALTER TABLE `chats_info` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `discounts`
--

DROP TABLE IF EXISTS `discounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `discounts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `hash_id` varchar(100) NOT NULL,
  `type` varchar(10) NOT NULL,
  `amount` int NOT NULL,
  `expire_date` int NOT NULL,
  `expire_count` int NOT NULL,
  `used_by` text,
  `can_use` int NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `discounts`
--

LOCK TABLES `discounts` WRITE;
/*!40000 ALTER TABLE `discounts` DISABLE KEYS */;
INSERT INTO `discounts` VALUES (3,'XiikqIFeY','percent',15,1774337092,29,'[5443606075]',2);
/*!40000 ALTER TABLE `discounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `gift_list`
--

DROP TABLE IF EXISTS `gift_list`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `gift_list` (
  `id` int NOT NULL AUTO_INCREMENT,
  `server_id` int NOT NULL,
  `volume` int NOT NULL,
  `day` int NOT NULL,
  `offset` int DEFAULT '0',
  `server_offset` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gift_list`
--

LOCK TABLES `gift_list` WRITE;
/*!40000 ALTER TABLE `gift_list` DISABLE KEYS */;
/*!40000 ALTER TABLE `gift_list` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `increase_day`
--

DROP TABLE IF EXISTS `increase_day`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `increase_day` (
  `id` int NOT NULL AUTO_INCREMENT,
  `volume` float NOT NULL,
  `price` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `increase_day`
--

LOCK TABLES `increase_day` WRITE;
/*!40000 ALTER TABLE `increase_day` DISABLE KEYS */;
/*!40000 ALTER TABLE `increase_day` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `increase_order`
--

DROP TABLE IF EXISTS `increase_order`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `increase_order` (
  `id` int NOT NULL AUTO_INCREMENT,
  `userid` varchar(30) NOT NULL,
  `server_id` int NOT NULL,
  `inbound_id` int NOT NULL,
  `remark` varchar(100) NOT NULL,
  `amount` int NOT NULL,
  `date` varchar(30) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `increase_order`
--

LOCK TABLES `increase_order` WRITE;
/*!40000 ALTER TABLE `increase_order` DISABLE KEYS */;
/*!40000 ALTER TABLE `increase_order` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `increase_plan`
--

DROP TABLE IF EXISTS `increase_plan`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `increase_plan` (
  `id` int NOT NULL AUTO_INCREMENT,
  `volume` float NOT NULL,
  `price` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `increase_plan`
--

LOCK TABLES `increase_plan` WRITE;
/*!40000 ALTER TABLE `increase_plan` DISABLE KEYS */;
/*!40000 ALTER TABLE `increase_plan` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `needed_sofwares`
--

DROP TABLE IF EXISTS `needed_sofwares`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `needed_sofwares` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `link` varchar(250) NOT NULL,
  `status` int NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `needed_sofwares`
--

LOCK TABLES `needed_sofwares` WRITE;
/*!40000 ALTER TABLE `needed_sofwares` DISABLE KEYS */;
/*!40000 ALTER TABLE `needed_sofwares` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `orders_list`
--

DROP TABLE IF EXISTS `orders_list`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `orders_list` (
  `id` int NOT NULL AUTO_INCREMENT,
  `userid` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci NOT NULL,
  `token` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci NOT NULL,
  `transid` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci NOT NULL,
  `fileid` int NOT NULL,
  `server_id` int NOT NULL,
  `inbound_id` int NOT NULL DEFAULT '0',
  `remark` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci NOT NULL,
  `uuid` text CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci,
  `protocol` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci NOT NULL,
  `expire_date` int NOT NULL,
  `link` text CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci NOT NULL,
  `amount` int NOT NULL,
  `status` int NOT NULL,
  `date` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci NOT NULL,
  `notif` int NOT NULL DEFAULT '0',
  `rahgozar` int DEFAULT '0',
  `agent_bought` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `orders_list`
--

LOCK TABLES `orders_list` WRITE;
/*!40000 ALTER TABLE `orders_list` DISABLE KEYS */;
/*!40000 ALTER TABLE `orders_list` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pays`
--

DROP TABLE IF EXISTS `pays`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pays` (
  `id` int NOT NULL AUTO_INCREMENT,
  `hash_id` varchar(1000) NOT NULL,
  `description` varchar(5000) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `payid` varchar(500) DEFAULT NULL,
  `user_id` bigint NOT NULL,
  `type` varchar(100) DEFAULT NULL,
  `plan_id` int DEFAULT NULL,
  `volume` int DEFAULT NULL,
  `day` int DEFAULT NULL,
  `price` int NOT NULL,
  `tron_price` double(255,2) NOT NULL DEFAULT '0.00',
  `request_date` int NOT NULL,
  `state` varchar(255) NOT NULL,
  `agent_bought` int NOT NULL DEFAULT '0',
  `agent_count` int NOT NULL DEFAULT '0',
  `message_id` int DEFAULT NULL,
  `chat_id` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pays`
--

LOCK TABLES `pays` WRITE;
/*!40000 ALTER TABLE `pays` DISABLE KEYS */;
INSERT INTO `pays` VALUES (2,'BLYd441gQ',NULL,NULL,6668176130,'INCREASE_WALLET',0,0,0,62800,0.00,1771782822,'have_sent',0,0,63,'7108082722');
/*!40000 ALTER TABLE `pays` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `reseller_bots`
--

DROP TABLE IF EXISTS `reseller_bots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reseller_bots` (
  `id` int NOT NULL AUTO_INCREMENT,
  `owner_userid` bigint NOT NULL,
  `bot_token` varchar(255) NOT NULL,
  `bot_tg_id` bigint DEFAULT NULL,
  `bot_username` varchar(120) DEFAULT NULL,
  `admin_userid` bigint NOT NULL DEFAULT '0',
  `created_at` int NOT NULL DEFAULT '0',
  `expires_at` int NOT NULL DEFAULT '0',
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `exp_notify_sent` tinyint(1) NOT NULL DEFAULT '0',
  `backup_auto` tinyint(1) NOT NULL DEFAULT '0',
  `last_backup_at` int NOT NULL DEFAULT '0',
  `db_name` varchar(190) DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `deleted_at` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `owner_userid` (`owner_userid`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reseller_bots`
--

LOCK TABLES `reseller_bots` WRITE;
/*!40000 ALTER TABLE `reseller_bots` DISABLE KEYS */;
/*!40000 ALTER TABLE `reseller_bots` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `reseller_plans`
--

DROP TABLE IF EXISTS `reseller_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reseller_plans` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(120) NOT NULL,
  `days` int NOT NULL DEFAULT '30',
  `price` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reseller_plans`
--

LOCK TABLES `reseller_plans` WRITE;
/*!40000 ALTER TABLE `reseller_plans` DISABLE KEYS */;
/*!40000 ALTER TABLE `reseller_plans` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `send_list`
--

DROP TABLE IF EXISTS `send_list`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `send_list` (
  `id` int NOT NULL AUTO_INCREMENT,
  `offset` int NOT NULL DEFAULT '0',
  `type` varchar(20) NOT NULL,
  `text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `chat_id` bigint DEFAULT NULL,
  `message_id` int DEFAULT NULL,
  `file_id` varchar(500) DEFAULT NULL,
  `state` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `send_list`
--

LOCK TABLES `send_list` WRITE;
/*!40000 ALTER TABLE `send_list` DISABLE KEYS */;
/*!40000 ALTER TABLE `send_list` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `server_categories`
--

DROP TABLE IF EXISTS `server_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `server_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `server_id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci NOT NULL,
  `title` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci NOT NULL,
  `parent` int NOT NULL DEFAULT '0',
  `step` int NOT NULL,
  `active` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `server_categories`
--

LOCK TABLES `server_categories` WRITE;
/*!40000 ALTER TABLE `server_categories` DISABLE KEYS */;
/*!40000 ALTER TABLE `server_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `server_config`
--

DROP TABLE IF EXISTS `server_config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `server_config` (
  `id` int NOT NULL AUTO_INCREMENT,
  `panel_url` varchar(254) NOT NULL,
  `ip` text NOT NULL,
  `sni` varchar(254) NOT NULL,
  `header_type` enum('none','http') NOT NULL,
  `request_header` text NOT NULL,
  `response_header` text NOT NULL,
  `security` enum('xtls','tls','none') NOT NULL,
  `tlsSettings` text NOT NULL,
  `type` varchar(100) DEFAULT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(100) NOT NULL,
  `port_type` varchar(10) DEFAULT 'auto',
  `reality` varchar(10) DEFAULT 'false',
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `server_config`
--

LOCK TABLES `server_config` WRITE;
/*!40000 ALTER TABLE `server_config` DISABLE KEYS */;
/*!40000 ALTER TABLE `server_config` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `server_info`
--

DROP TABLE IF EXISTS `server_info`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `server_info` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `ucount` varchar(20) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `remark` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci NOT NULL,
  `flag` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci NOT NULL,
  `active` int NOT NULL DEFAULT '0',
  `state` int NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `server_info`
--

LOCK TABLES `server_info` WRITE;
/*!40000 ALTER TABLE `server_info` DISABLE KEYS */;
/*!40000 ALTER TABLE `server_info` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `server_plans`
--

DROP TABLE IF EXISTS `server_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `server_plans` (
  `id` int NOT NULL AUTO_INCREMENT,
  `fileid` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci NOT NULL,
  `catid` int NOT NULL,
  `server_id` int NOT NULL,
  `inbound_id` int NOT NULL DEFAULT '0',
  `acount` bigint NOT NULL,
  `limitip` int NOT NULL DEFAULT '1',
  `title` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci NOT NULL,
  `protocol` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci NOT NULL,
  `days` float NOT NULL,
  `volume` float NOT NULL,
  `type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci NOT NULL,
  `price` int NOT NULL,
  `descr` text CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci NOT NULL,
  `pic` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci NOT NULL,
  `active` int NOT NULL DEFAULT '0',
  `step` int NOT NULL,
  `date` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci NOT NULL,
  `rahgozar` int DEFAULT '0',
  `dest` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `serverNames` text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `spiderX` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci DEFAULT NULL,
  `flow` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci DEFAULT 'None',
  `custom_path` int DEFAULT '1',
  `custom_port` int NOT NULL DEFAULT '0',
  `custom_sni` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `server_plans`
--

LOCK TABLES `server_plans` WRITE;
/*!40000 ALTER TABLE `server_plans` DISABLE KEYS */;
/*!40000 ALTER TABLE `server_plans` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `servers`
--

DROP TABLE IF EXISTS `servers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `servers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ip` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci NOT NULL,
  `port` int NOT NULL,
  `username` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci NOT NULL,
  `password` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci NOT NULL,
  `name` varchar(200) CHARACTER SET utf8mb3 COLLATE utf8mb3_persian_ci NOT NULL,
  `panel` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci NOT NULL,
  `status` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `servers`
--

LOCK TABLES `servers` WRITE;
/*!40000 ALTER TABLE `servers` DISABLE KEYS */;
/*!40000 ALTER TABLE `servers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `setting`
--

DROP TABLE IF EXISTS `setting`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `setting` (
  `id` int NOT NULL AUTO_INCREMENT,
  `type` varchar(5000) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `setting`
--

LOCK TABLES `setting` WRITE;
/*!40000 ALTER TABLE `setting` DISABLE KEYS */;
INSERT INTO `setting` VALUES (1,'BOT_STATES','{\"sellState\":\"on\",\"botState\":\"on\",\"subLinkState\":\"on\",\"agencyState\":\"on\",\"testAccount\":\"on\",\"cartToCartAutoAcceptState\":\"off\",\"cartToCartAutoAcceptTime\":\"1\",\"cartToCartState\":\"on\",\"walletState\":\"on\"}'),(2,'PAYMENT_KEYS','{\"bankAccount\":\"6262626212111212\",\"holderName\":\"name\"}');
/*!40000 ALTER TABLE `setting` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `userid` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci NOT NULL,
  `username` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci NOT NULL,
  `refcode` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci NOT NULL,
  `wallet` int NOT NULL DEFAULT '0',
  `date` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci NOT NULL,
  `phone` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci DEFAULT NULL,
  `refered_by` bigint DEFAULT NULL,
  `step` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'none',
  `freetrial` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci DEFAULT NULL,
  `isAdmin` tinyint(1) NOT NULL DEFAULT '0',
  `first_start` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci DEFAULT NULL,
  `temp` text CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `is_agent` int NOT NULL DEFAULT '0',
  `discount_percent` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `agent_date` int NOT NULL DEFAULT '0',
  `spam_info` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_persian_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_persian_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'7108082722','...',' ندارد ','0',0,'1771782261',NULL,NULL,'none',NULL,0,NULL,'',0,NULL,0,NULL),(2,'6668176130','پشتیبانی دلتا وی پی ان','delta_vpn12','0',0,'1771782273',NULL,NULL,'none',NULL,0,'sent','',2,NULL,0,'{\"count\":8,\"date\":1771782870}');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-02-22 17:58:27

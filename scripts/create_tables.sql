-- MariaDB dump 10.18  Distrib 10.4.17-MariaDB, for Win64 (AMD64)
--
-- Host: 127.0.0.1    Database: whitten_starmap
-- ------------------------------------------------------
-- Server version	10.4.17-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `hyg`
--

DROP TABLE IF EXISTS `hyg`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `hyg` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `hip` int(11) DEFAULT NULL,
  `hd` int(11) DEFAULT NULL,
  `hr` int(11) DEFAULT NULL,
  `gl` varchar(16) DEFAULT NULL,
  `bf` varchar(16) DEFAULT NULL,
  `iauname` varchar(128) DEFAULT NULL,
  `altname` varchar(128) DEFAULT NULL,
  `ra` double DEFAULT NULL,
  `dec` double DEFAULT NULL,
  `dist` float DEFAULT NULL,
  `pmra` float DEFAULT NULL,
  `pmdec` float DEFAULT NULL,
  `rv` float DEFAULT NULL,
  `mag` float DEFAULT NULL,
  `absmag` float DEFAULT NULL,
  `spect` varchar(16) DEFAULT NULL,
  `ci` float DEFAULT NULL,
  `x` float DEFAULT NULL,
  `y` float DEFAULT NULL,
  `z` float DEFAULT NULL,
  `x_eq` float DEFAULT NULL,
  `y_eq` float DEFAULT NULL,
  `z_eq` float DEFAULT NULL,
  `vx_eq` float DEFAULT NULL,
  `vy_eq` float DEFAULT NULL,
  `vz_eq` float DEFAULT NULL,
  `rarad` float DEFAULT NULL,
  `decrad` float DEFAULT NULL,
  `pmrarad` float DEFAULT NULL,
  `pmdecrad` float DEFAULT NULL,
  `bayer` varchar(16) DEFAULT NULL,
  `flam` int(11) DEFAULT NULL,
  `con` varchar(16) DEFAULT NULL,
  `comp` int(11) DEFAULT NULL,
  `comp_primary` int(11) DEFAULT NULL,
  `base` varchar(16) DEFAULT NULL,
  `lum` float DEFAULT NULL,
  `var` varchar(16) DEFAULT NULL,
  `var_min` float DEFAULT NULL,
  `var_max` float DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=119621 DEFAULT CHARSET=utf8;

--
-- Table structure for table `athyg`
--

DROP TABLE IF EXISTS `athyg`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `athyg` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tyc` varchar(255) DEFAULT NULL,
  `gaia` varchar(255) DEFAULT NULL,
  `hyg` int(11) DEFAULT NULL,
  `hip` varchar(255) DEFAULT NULL,
  `hd` varchar(255) DEFAULT NULL,
  `hr` varchar(255) DEFAULT NULL,
  `gl` varchar(16) DEFAULT NULL,
  `bayer` varchar(16) DEFAULT NULL,
  `flam` int(11) DEFAULT NULL,
  `con` varchar(16) DEFAULT NULL,
  `proper` varchar(128) DEFAULT NULL,
  `ra` double DEFAULT NULL,
  `dec` double DEFAULT NULL,
  `pos_src` varchar(16) DEFAULT NULL,
  `dist` float DEFAULT NULL,
  `x` float DEFAULT NULL,
  `y` float DEFAULT NULL,
  `z` float DEFAULT NULL,
  `x_eq` float DEFAULT NULL,
  `y_eq` float DEFAULT NULL,
  `z_eq` float DEFAULT NULL,
  `dist_src` varchar(16) DEFAULT NULL,
  `mag` float DEFAULT NULL,
  `absmag` float DEFAULT NULL,
  `mag_src` varchar(16) DEFAULT NULL,
  `spect` varchar(16) DEFAULT NULL, /* from HYG3 */
  `spect_src` varchar(16) DEFAULT NULL, /* HYG */
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Table structure for table `gcns`
--

DROP TABLE IF EXISTS `gcns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `gcns` (
  `gaia` varchar(255) NOT NULL,
  `main_id` varchar(255) DEFAULT NULL,
  `ra` float NOT NULL,
  `dec` float NOT NULL,
  `plx` float DEFAULT NULL,
  `pmRA` float DEFAULT NULL,
  `pmDE` float DEFAULT NULL,
  `Gmag` float DEFAULT NULL,
  `BPmag` float DEFAULT NULL,
  `RPmag` float DEFAULT NULL,
  `Bmag` float DEFAULT NULL,
  `Vmag` float DEFAULT NULL,
  `RV` float DEFAULT NULL,
  `sp_type` varchar(16),
  `WDprob` float DEFAULT NULL,
  `dist` float NOT NULL,
  `x` float NOT NULL,
  `y` float NOT NULL,
  `z` float NOT NULL,
  `U` float DEFAULT NULL,
  `V` float DEFAULT NULL,
  `W` float DEFAULT NULL,
  `main_type` varchar(50),
  PRIMARY KEY (`gaia`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Table structure for table `trek`
--

DROP TABLE IF EXISTS `trek`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `trek` (
  `hyg_id` int(11) NOT NULL,
  `name` varchar(45) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

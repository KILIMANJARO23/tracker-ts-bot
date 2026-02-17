-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Jan 24, 2026 at 02:42 AM
-- Server version: 10.11.10-MariaDB-log
-- PHP Version: 7.4.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `tg_bot`
--

-- --------------------------------------------------------

--
-- Table structure for table `goals`
--

CREATE TABLE `goals` (
  `id` int(11) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `title` varchar(255) NOT NULL,
  `category` varchar(50) DEFAULT NULL,
  `deadline` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `goals`
--

INSERT INTO `goals` (`id`, `user_id`, `title`, `category`, `deadline`) VALUES
(1, 5830587718, 'qqq111', 'prj', NULL),
(4, 5830587718, 'ййй', 'prj', NULL),
(5, 5830587718, 'www', 'prj', '2026-12-20'),
(6, 775829128, 'Купить авто', 'fin', '1970-01-01');

-- --------------------------------------------------------

--
-- Table structure for table `goal_steps`
--

CREATE TABLE `goal_steps` (
  `id` int(11) NOT NULL,
  `goal_id` int(11) NOT NULL,
  `step_text` varchar(255) NOT NULL,
  `is_done` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `goal_steps`
--

INSERT INTO `goal_steps` (`id`, `goal_id`, `step_text`, `is_done`) VALUES
(4, 4, 'й1', 0),
(5, 4, 'й2', 0),
(6, 4, 'й3', 0),
(7, 5, 'w1', 0),
(9, 5, 'w3', 0),
(10, 1, 'q11', 0),
(12, 5, 'w4', 0),
(13, 6, 'Заработать первый миллион', 0);

-- --------------------------------------------------------

--
-- Table structure for table `habits`
--

CREATE TABLE `habits` (
  `id` int(11) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `title` varchar(255) NOT NULL,
  `days` varchar(255) NOT NULL,
  `notify` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `habits`
--

INSERT INTO `habits` (`id`, `user_id`, `title`, `days`, `notify`) VALUES
(3, 775829128, 'Пить воду', '1,2,3,4,5,6,7', 1);

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` int(11) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `type` enum('inc','exp') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `category` varchar(50) NOT NULL,
  `goal_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

--
-- Dumping data for table `transactions`
--

INSERT INTO `transactions` (`id`, `user_id`, `type`, `amount`, `category`, `goal_id`, `created_at`) VALUES
(1, 5830587718, 'inc', 222.00, 'inv', NULL, '2026-01-23 16:03:28'),
(3, 775829128, 'exp', 4.00, 'trans', 6, '2026-01-23 17:25:48');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) NOT NULL,
  `chat_id` bigint(20) NOT NULL,
  `state` varchar(50) DEFAULT 'MAIN_MENU',
  `last_msg_id` bigint(20) DEFAULT NULL,
  `temp_data` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `chat_id`, `state`, `last_msg_id`, `temp_data`) VALUES
(775829128, 775829128, 'MAIN_MENU', 5259, NULL),
(5830587718, 5830587718, 'GOALS_MENU', 5262, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `goals`
--
ALTER TABLE `goals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `goal_steps`
--
ALTER TABLE `goal_steps`
  ADD PRIMARY KEY (`id`),
  ADD KEY `goal_id` (`goal_id`);

--
-- Indexes for table `habits`
--
ALTER TABLE `habits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `goal_id` (`goal_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `goals`
--
ALTER TABLE `goals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `goal_steps`
--
ALTER TABLE `goal_steps`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `habits`
--
ALTER TABLE `habits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `goal_steps`
--
ALTER TABLE `goal_steps`
  ADD CONSTRAINT `fk_goal` FOREIGN KEY (`goal_id`) REFERENCES `goals` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`goal_id`) REFERENCES `goals` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

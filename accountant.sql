-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 04, 2025 at 04:40 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `accountant`
--

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `id` int(11) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `sender_id` int(11) NOT NULL,
  `receiver_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `is_resolved` tinyint(1) NOT NULL DEFAULT 0,
  `parent_id` int(11) DEFAULT NULL,
  `message_type` enum('concern','reminder','casual') NOT NULL DEFAULT 'casual',
  `is_draft` tinyint(1) NOT NULL DEFAULT 0,
  `is_group` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `messages`
--

INSERT INTO `messages` (`id`, `subject`, `message`, `sender_id`, `receiver_id`, `created_at`, `is_read`, `is_resolved`, `parent_id`, `message_type`, `is_draft`, `is_group`) VALUES
(33, 'Payroll', 'himoa ang payroll', 1, 4, '2025-03-26 03:57:37', 1, 0, NULL, 'casual', 0, 0),
(34, 'Re: Payroll', 'yes sir, thank you.', 4, 1, '2025-03-26 03:58:19', 1, 1, 33, 'casual', 0, 0),
(35, 'sss', '', 4, 1, '2025-03-26 20:29:55', 1, 1, NULL, 'concern', 0, 0),
(36, 'hinpo', 'haha', 4, 1, '2025-03-27 01:38:33', 1, 1, NULL, 'concern', 0, 0),
(37, ',nmn', 'mnbn', 4, 1, '2025-03-27 03:55:47', 0, 1, NULL, 'concern', 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `message_attachments`
--

CREATE TABLE `message_attachments` (
  `id` int(11) NOT NULL,
  `message_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_size` int(11) NOT NULL,
  `file_type` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `message_recipients`
--

CREATE TABLE `message_recipients` (
  `id` int(11) NOT NULL,
  `message_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` varchar(50) NOT NULL,
  `message_id` int(11) DEFAULT NULL,
  `task_id` int(11) DEFAULT NULL,
  `submission_id` int(11) DEFAULT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `read` tinyint(1) NOT NULL DEFAULT 0,
  `sender_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `type`, `message_id`, `task_id`, `submission_id`, `message`, `created_at`, `read`, `sender_id`) VALUES
(28, 4, 'new_message', 33, NULL, NULL, '', '2025-03-26 03:57:37', 1, NULL),
(29, 1, 'new_reply', 34, NULL, NULL, '', '2025-03-26 03:58:19', 1, NULL),
(30, 4, 'resolved', 34, NULL, NULL, 'Your message has been marked as resolved', '2025-03-26 14:01:12', 1, NULL),
(53, 1, 'new_message', 35, NULL, NULL, '', '2025-03-26 20:29:55', 1, NULL),
(54, 4, 'resolved', 35, NULL, NULL, 'Your message has been marked as resolved', '2025-03-26 20:31:02', 0, NULL),
(60, 1, 'new_message', 36, NULL, NULL, '', '2025-03-27 01:38:33', 1, NULL),
(61, 4, 'resolved', 36, NULL, NULL, 'Your message has been marked as resolved', '2025-03-27 01:39:32', 0, NULL),
(72, 4, 'task_assigned', NULL, 25, NULL, 'You have been assigned a new task', '2025-03-27 03:00:40', 0, NULL),
(73, 4, 'task_approved', NULL, 25, NULL, '0', '2025-03-27 03:08:02', 0, 1),
(74, 4, 'task_assigned', NULL, 26, NULL, 'You have been assigned a new task', '2025-03-27 03:08:35', 0, NULL),
(75, 4, 'task_assigned', NULL, 27, NULL, 'You have been assigned a new task', '2025-03-27 03:22:07', 0, NULL),
(76, 1, 'task_submitted', NULL, 27, NULL, '0', '2025-03-27 03:29:49', 0, 4),
(77, 1, 'new_message', 37, NULL, NULL, '', '2025-03-27 03:55:47', 1, NULL),
(78, 4, 'resolved', 37, NULL, NULL, 'Your message has been marked as resolved', '2025-03-27 03:56:28', 0, NULL),
(79, 4, 'task_resubmit', NULL, 27, NULL, '0', '2025-03-27 03:56:36', 1, 1),
(80, 1, 'task_submitted', NULL, 27, NULL, '0', '2025-03-27 03:56:58', 0, 4),
(81, 4, 'task_assigned', NULL, 28, NULL, 'You have been assigned a new task', '2025-03-27 08:10:38', 0, NULL),
(82, 1, 'task_submitted', NULL, 28, NULL, '0', '2025-03-27 08:11:41', 0, 4);

-- --------------------------------------------------------

--
-- Table structure for table `recurring_tasks`
--

CREATE TABLE `recurring_tasks` (
  `id` int(11) NOT NULL,
  `task_id` int(11) NOT NULL,
  `created_by` int(11) NOT NULL,
  `frequency` enum('daily','weekly','monthly') NOT NULL,
  `interval_value` int(11) NOT NULL DEFAULT 1,
  `weekdays` varchar(20) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `last_run_date` datetime DEFAULT NULL,
  `next_run_date` datetime NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `value` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `name`, `value`, `created_at`, `updated_at`) VALUES
(1, 'motd_quote', 'Happy Birthday, Sandy', '2025-03-23 09:19:48', '2025-03-27 00:11:27');

-- --------------------------------------------------------

--
-- Table structure for table `submission_attachments`
--

CREATE TABLE `submission_attachments` (
  `id` int(11) NOT NULL,
  `submission_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tasks`
--

CREATE TABLE `tasks` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `created_by` int(11) NOT NULL,
  `assigned_to` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `due_date` datetime NOT NULL,
  `status` enum('pending','completed','overdue') NOT NULL DEFAULT 'pending',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `priority` enum('low','medium','high') DEFAULT 'medium',
  `completion_notes` text DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tasks`
--

INSERT INTO `tasks` (`id`, `title`, `description`, `created_by`, `assigned_to`, `created_at`, `due_date`, `status`, `updated_at`, `priority`, `completion_notes`, `completed_at`) VALUES
(25, 'hahahaha', 'haha', 1, 4, '2025-03-27 03:00:40', '2025-03-28 11:00:00', 'completed', '2025-03-27 03:08:02', 'medium', '', '2025-03-27 03:08:02'),
(26, 'sas', 'sas', 1, 4, '2025-03-27 03:08:35', '2025-03-27 11:09:00', 'completed', '2025-03-27 03:10:16', 'medium', NULL, NULL),
(27, 'TRISTAN', 'HAHAHAH', 1, 4, '2025-03-27 03:22:07', '2025-03-27 11:22:00', 'completed', '2025-03-27 03:56:58', 'medium', NULL, NULL),
(28, 'Wireframe', 'create wireframe for a propose system', 1, 4, '2025-03-27 08:10:38', '2025-03-27 16:08:00', 'completed', '2025-03-27 08:11:41', 'low', NULL, NULL),
(29, 'Complete monthly report', 'Finish the financial report for this month', 1, 1, '2025-05-04 02:36:21', '2025-05-04 17:00:00', 'pending', '2025-05-04 02:36:21', 'high', NULL, NULL),
(30, 'Client meeting preparation', 'Prepare documents for client meeting', 1, 1, '2025-05-04 02:36:21', '2025-05-04 10:00:00', 'completed', '2025-05-04 02:36:21', 'medium', NULL, '2025-05-03 20:36:21'),
(31, 'Tax filing deadline', 'Submit tax documents before deadline', 1, 1, '2025-05-04 02:36:21', '2025-05-03 23:59:59', 'pending', '2025-05-04 02:36:21', 'high', NULL, NULL),
(32, 'Team meeting', 'Weekly team progress meeting', 1, 1, '2025-05-04 02:36:21', '2025-05-05 14:00:00', 'pending', '2025-05-04 02:36:21', 'medium', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `task_attachments`
--

CREATE TABLE `task_attachments` (
  `id` int(11) NOT NULL,
  `task_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_type` varchar(100) NOT NULL,
  `file_size` int(11) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `uploaded_by` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `task_submissions`
--

CREATE TABLE `task_submissions` (
  `id` int(11) NOT NULL,
  `task_id` int(11) NOT NULL,
  `submitted_by` int(11) NOT NULL,
  `submission_text` text NOT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `review_notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `task_submissions`
--

INSERT INTO `task_submissions` (`id`, `task_id`, `submitted_by`, `submission_text`, `submitted_at`, `status`, `reviewed_by`, `reviewed_at`, `review_notes`) VALUES
(19, 25, 4, 'hihi', '2025-03-27 03:01:22', 'pending', NULL, NULL, NULL),
(20, 25, 4, 'asas', '2025-03-27 03:06:52', 'approved', 1, '2025-03-27 03:08:02', ''),
(21, 26, 4, 'okkk', '2025-03-27 03:10:16', 'pending', NULL, NULL, NULL),
(22, 27, 4, 'haha', '2025-03-27 03:29:49', 'rejected', 1, '2025-03-27 03:56:36', ''),
(23, 27, 4, 'done', '2025-03-27 03:56:58', 'pending', NULL, NULL, NULL),
(24, 28, 4, 'asdas', '2025-03-27 08:11:41', 'pending', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `task_templates`
--

CREATE TABLE `task_templates` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `priority` enum('low','medium','high') DEFAULT 'medium',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_public` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `task_templates`
--

INSERT INTO `task_templates` (`id`, `name`, `description`, `priority`, `created_by`, `created_at`, `is_public`) VALUES
(1, 'natnat', 'hihi', 'medium', 4, '2025-03-27 00:53:13', 1);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','staff') NOT NULL DEFAULT 'staff',
  `phone` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `first_name`, `last_name`, `middle_name`, `username`, `password`, `role`, `phone`) VALUES
(1, 'Sandy', 'Panong', 'Jean A.', 'Sandy', 'sandy12', 'admin', NULL),
(2, 'reyel', 'ann', 'ann', 'ann', 'ann', 'staff', ''),
(4, 'mak', 'mak', 'mak', 'mak', 'mak', 'staff', NULL),
(5, 'rfsdf', 'sdfdsf', NULL, 'makdsdf', 'makdsfs', 'staff', '999999');

-- --------------------------------------------------------

--
-- Table structure for table `user_ratings`
--

CREATE TABLE `user_ratings` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `rating` decimal(3,2) NOT NULL DEFAULT 3.00,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_ratings`
--

INSERT INTO `user_ratings` (`id`, `user_id`, `rating`, `last_updated`) VALUES
(1, 2, 3.00, '2025-03-26 19:58:02'),
(2, 4, 3.00, '2025-03-26 19:58:02'),
(3, 5, 3.00, '2025-03-26 19:58:02'),
(4, 2, 3.00, '2025-03-27 00:33:44'),
(5, 4, 3.17, '2025-03-27 00:33:44');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sender_id` (`sender_id`),
  ADD KEY `receiver_id` (`receiver_id`),
  ADD KEY `parent_id` (`parent_id`);

--
-- Indexes for table `message_attachments`
--
ALTER TABLE `message_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `message_id` (`message_id`);

--
-- Indexes for table `message_recipients`
--
ALTER TABLE `message_recipients`
  ADD PRIMARY KEY (`id`),
  ADD KEY `message_id` (`message_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `message_id` (`message_id`),
  ADD KEY `task_id` (`task_id`),
  ADD KEY `submission_id` (`submission_id`),
  ADD KEY `sender_id` (`sender_id`);

--
-- Indexes for table `recurring_tasks`
--
ALTER TABLE `recurring_tasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `task_id` (`task_id`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `submission_attachments`
--
ALTER TABLE `submission_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `submission_id` (`submission_id`);

--
-- Indexes for table `tasks`
--
ALTER TABLE `tasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `assigned_to` (`assigned_to`),
  ADD KEY `idx_task_status` (`status`),
  ADD KEY `idx_task_due_date` (`due_date`);

--
-- Indexes for table `task_attachments`
--
ALTER TABLE `task_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `task_id` (`task_id`),
  ADD KEY `uploaded_by` (`uploaded_by`);

--
-- Indexes for table `task_submissions`
--
ALTER TABLE `task_submissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `task_id` (`task_id`),
  ADD KEY `submitted_by` (`submitted_by`),
  ADD KEY `reviewed_by` (`reviewed_by`);

--
-- Indexes for table `task_templates`
--
ALTER TABLE `task_templates`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `user_ratings`
--
ALTER TABLE `user_ratings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

--
-- AUTO_INCREMENT for table `message_attachments`
--
ALTER TABLE `message_attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `message_recipients`
--
ALTER TABLE `message_recipients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=83;

--
-- AUTO_INCREMENT for table `recurring_tasks`
--
ALTER TABLE `recurring_tasks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `submission_attachments`
--
ALTER TABLE `submission_attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tasks`
--
ALTER TABLE `tasks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `task_attachments`
--
ALTER TABLE `task_attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `task_submissions`
--
ALTER TABLE `task_submissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `task_templates`
--
ALTER TABLE `task_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `user_ratings`
--
ALTER TABLE `user_ratings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `messages`
--
ALTER TABLE `messages`
  ADD CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `messages_ibfk_3` FOREIGN KEY (`parent_id`) REFERENCES `messages` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `message_attachments`
--
ALTER TABLE `message_attachments`
  ADD CONSTRAINT `message_attachments_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `message_recipients`
--
ALTER TABLE `message_recipients`
  ADD CONSTRAINT `message_recipients_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `message_recipients_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notifications_ibfk_3` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notifications_ibfk_4` FOREIGN KEY (`submission_id`) REFERENCES `task_submissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notifications_ibfk_5` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `recurring_tasks`
--
ALTER TABLE `recurring_tasks`
  ADD CONSTRAINT `recurring_tasks_ibfk_1` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `submission_attachments`
--
ALTER TABLE `submission_attachments`
  ADD CONSTRAINT `submission_attachments_ibfk_1` FOREIGN KEY (`submission_id`) REFERENCES `task_submissions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tasks`
--
ALTER TABLE `tasks`
  ADD CONSTRAINT `tasks_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `tasks_ibfk_2` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`);

--
-- Constraints for table `task_attachments`
--
ALTER TABLE `task_attachments`
  ADD CONSTRAINT `task_attachments_ibfk_1` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `task_attachments_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `task_submissions`
--
ALTER TABLE `task_submissions`
  ADD CONSTRAINT `task_submissions_ibfk_1` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`),
  ADD CONSTRAINT `task_submissions_ibfk_2` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `task_submissions_ibfk_3` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `user_ratings`
--
ALTER TABLE `user_ratings`
  ADD CONSTRAINT `user_ratings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

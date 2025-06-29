--
-- Table structure for table `contactmanager_tracking`
--

CREATE TABLE IF NOT EXISTS /*_*/contactmanager_tracking (
	`id` int(11) NOT NULL,
	`page_id` int(11) NOT NULL,
	`email` varchar(255) NOT NULL,
	`name` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
	`event_type` varchar(50) NULL,
	`mailer` varchar(255) NOT NULL,
	`created_at` datetime NOT NULL,
	`updated_at` datetime NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Indexes for table `sendgrid_events`
--
ALTER TABLE /*_*/contactmanager_tracking
	ADD PRIMARY KEY (`id`);

ALTER TABLE /*_*/contactmanager_tracking
	ADD INDEX `page_id` (`page_id`);

ALTER TABLE /*_*/contactmanager_tracking
	ADD INDEX `email` (`email`);

--
-- AUTO_INCREMENT for table `contactmanager_tracking`
--
ALTER TABLE /*_*/contactmanager_tracking
	MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


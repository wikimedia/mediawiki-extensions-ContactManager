--
-- Table structure for table `contactmanager_sent`
--

CREATE TABLE IF NOT EXISTS /*_*/contactmanager_sent (
	`id` int(11) NOT NULL,
 	`user` int(11) NOT NULL,
	`mailbox` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
	`subject` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
	`page_id` int(11) NOT NULL,
	`account` varchar(255) NOT NULL,
	`recipients` int(11) NULL,
	`created_at` datetime NOT NULL,
	`updated_at` datetime NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Indexes for table `sendgrid_events`
--
ALTER TABLE /*_*/contactmanager_sent
	ADD PRIMARY KEY (`id`);

ALTER TABLE /*_*/contactmanager_sent
	ADD INDEX `user` (`user`);

ALTER TABLE /*_*/contactmanager_sent
	ADD INDEX `mailbox` (`mailbox`);

ALTER TABLE /*_*/contactmanager_sent
	ADD INDEX `page_id` (`page_id`);

--
-- AUTO_INCREMENT for table `contactmanager_tracking`
--
ALTER TABLE /*_*/contactmanager_sent
	MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;


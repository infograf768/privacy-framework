<?php
/**
 * @package     Joomla.Administrator
 * @subpackage  com_actionlogs
 *
 * @copyright   Copyright (C) 2005 - 2018 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;

JLoader::register('ActionlogsHelper', JPATH_ADMINISTRATOR . '/components/com_actionlogs/helpers/actionlogs.php');

/**
 * Methods supporting a list of article records.
 *
 * @since  __DEPLOY_VERSION__
 */
class ActionlogsModelActionlog extends JModelLegacy
{
	/**
	 * Function to add logs to the database
	 * This method adds a record to #__action_logs contains (message_language_key, message, date, context, user)
	 *
	 * @param   array    $messages            The contents of the messages to be logged
	 * @param   string   $messageLanguageKey  The language key of the message
	 * @param   string   $context             The context of the content passed to the plugin
	 * @param   int      $userId              ID of user perform the action, usually ID of current logged in user
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function addLogsToDb($messages, $messageLanguageKey, $context, $userId = null)
	{
		$user   = JFactory::getUser($userId);
		$db     = $this->getDbo();
		$date   = JFactory::getDate();
		$params = ComponentHelper::getComponent('com_actionlogs')->getParams();

		if ($params->get('ip_logging', 0))
		{
			$ip = JFactory::getApplication()->input->server->get('REMOTE_ADDR');
		}
		else
		{
			$ip = 'COM_ACTIONLOGS_DISABLED';
		}

		$loggedMessages = array();

		foreach ($messages as $message)
		{
			$logMessage                       = new stdClass;
			$logMessage->message_language_key = $messageLanguageKey;
			$logMessage->message              = json_encode($message);
			$logMessage->log_date             = (string) $date;
			$logMessage->extension            = $context;
			$logMessage->user_id              = $user->id;
			$logMessage->ip_address           = $ip;
			$logMessage->item_id              = isset($message['id']) ? (int) $message['id'] : 0;

			try
			{
				$db->insertObject('#__action_logs', $logMessage);
				$loggedMessages[] = $logMessage;

			}
			catch (RuntimeException $e)
			{
				// Ignore it
			}
		}

		// Send notification email to users who choose to be notified about the action logs
		$this->sendNotificationEmails($loggedMessages, $user->name, $context);
	}

	/**
	 * Send notification emails about the action log
	 *
	 * @param   array   $messages  The logged messages
	 * @param   string  $username  The username
	 * @param   string  $context   The Context
	 *
	 * @return  void
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	protected function sendNotificationEmails($messages, $username, $context)
	{
		$db           = $this->getDbo();
		$query        = $db->getQuery(true);
		$params       = ComponentHelper::getParams('com_actionlogs');
		$showIpColumn = (bool) $params->get('ip_logging', 0);

		$query->select($db->quoteName(array('email', 'params')))
			->from($db->quoteName('#__users'))
			->where($db->quoteName('params') . ' LIKE ' . $db->quote('%"logs_notification_option":"1"%'));

		$db->setQuery($query);

		try
		{
			$users = $db->loadObjectList();
		}
		catch (RuntimeException $e)
		{
			JError::raiseWarning(500, $e->getMessage());

			return;
		}

		$recipients = array();

		foreach ($users as $user)
		{
			$userParams = json_decode($user->params, true);
			$extensions = $userParams['logs_notification_extensions'];

			if (in_array(strtok($context, '.'), $extensions))
			{
				$recipients[] = $user->email;
			}
		}

		if (empty($recipients))
		{
			return;
		}

		$layout    = new JLayoutFile('components.com_actionlogs.layouts.logstable', JPATH_ADMINISTRATOR);
		$extension = ActionlogsHelper::translateExtensionName(strtoupper(strtok($context, '.')));

		foreach ($messages as $message)
		{
			$message->extension = $extension;
			$message->message   = ActionlogsHelper::getHumanReadableLogMessage($message);
		}

		$displayData = array(
			'messages'     => $messages,
			'username'     => $username,
			'showIpColumn' => $showIpColumn,
		);

		$body   = $layout->render($displayData);
		$mailer = JFactory::getMailer();
		$mailer->addRecipient($recipients);
		$mailer->setSubject(JText::_('COM_ACTIONLOGS_EMAIL_SUBJECT'));
		$mailer->isHTML(true);
		$mailer->Encoding = 'base64';
		$mailer->setBody($body);

		if (!$mailer->Send())
		{
			JError::raiseWarning(500, JText::_('JERROR_SENDING_EMAIL'));
		}
	}
}

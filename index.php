<?php

/**
 * ClassHard
 * 
 * @author Renhard Julindra
 * @version 1.0
 */

require __DIR__ . '/vendor/autoload.php';

if (php_sapi_name() != 'cli') {
	throw new Exception('This application must be run on the command line.');
}

date_default_timezone_set('Asia/Jakarta');

function getClient()
{
	$client = new Google_Client();
	$client->setApplicationName('Google Classroom API PHP Quickstart');
	$client->setScopes([Google_Service_Classroom::CLASSROOM_COURSES, Google_Service_Classroom::CLASSROOM_COURSEWORK_STUDENTS, Google_Service_Classroom::CLASSROOM_TOPICS]);
	$client->setAuthConfig(__DIR__ . '/credentials.json');
	$client->setAccessType('offline');
	$client->setPrompt('select_account consent');

	$tokenPath = __DIR__ . '/token.json';
	if (file_exists($tokenPath)) {
		$accessToken = json_decode(file_get_contents($tokenPath), true);
		$client->setAccessToken($accessToken);
	}

	if ($client->isAccessTokenExpired()) {
		if ($client->getRefreshToken()) {
			$client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
		} else {
			$authUrl = $client->createAuthUrl();
			printf("Open the following link in your browser:\n%s\n", $authUrl);
			print 'Enter verification code: ';
			$authCode = trim(fgets(STDIN));

			$accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
			$client->setAccessToken($accessToken);

			if (array_key_exists('error', $accessToken)) {
				throw new Exception(join(', ', $accessToken));
			}
		}

		if (!file_exists(dirname($tokenPath))) {
			mkdir(dirname($tokenPath), 0700, true);
		}
		file_put_contents($tokenPath, json_encode($client->getAccessToken()));
	}
	return $client;
}

function getCourseTopic($service, $type, $target, $courseId = null)
{
	$filename = __DIR__ . ($type == 'course' ? '/courses.json' : '/topics-' . $courseId . '.json');

	$id = null;
	if (file_exists($filename)) {
		$data = json_decode(file_get_contents($filename));
		foreach ($data as $d) {
			if ($d->name == $target) {
				$id = $d->id;
			}
		}
	}

	if (!$id) {
		$resData = null;
		if ($type == 'course') {
			$res = $service->courses->listCourses();
			$resData = $res->getCourses();
		} else {
			$res = $service->courses_topics->listCoursesTopics($courseId);
			$resData = $res->getTopic();
		}

		$newData = [];
		foreach ($resData as $d) {
			$curId = $type == 'course' ? $d->id : $d->topicId;
			if ($d->name == $target) {
				$id = $curId;
			}
			array_push($newData, ['id' => $curId, 'name' => $d->name]);
		}
		if (!file_exists(dirname($filename))) {
			mkdir(dirname($filename), 0700, true);
		}
		file_put_contents($filename, json_encode($newData));
	}

	return $id;
}

function createTopic($service, $courseId, $topic)
{
	$res = $service->courses_topics->create($courseId, new Google_Service_Classroom_Topic(['name' => $topic]));

	return $res->topicId;
}

function createCourseWorks($service, $courseId, $topicId, $courseWorks)
{
	foreach ($courseWorks as $c) {
		$dueTime = ['hours' => 16, 'minutes' => 59];
		if (property_exists($c, 'dueTime')) {
			$dueTime = ['hours' => ($c->dueTime->h - 7), 'minutes' => $c->dueTime->m];
		}

		$nowDate = date('Y-m-d');
		$nowDateArr = explode('-', $nowDate);
		$dueDate = ['year' => $nowDateArr[0], 'month' => $nowDateArr[1], 'day' => $nowDateArr[2]];
		if (property_exists($c, 'dueDays') && $c->dueDays > 0) {
			$newDueDate = explode('-', date('Y-m-d', strtotime($nowDate . ' + '.$c->dueDays.' days')));
			$dueDate = ['year' => $newDueDate[0], 'month' => $newDueDate[1], 'day' => $newDueDate[2]];
		}

		$body = new Google_Service_Classroom_CourseWork([
			'title' => $c->title,
			'description' => $c->description,
			'state' => 'PUBLISHED',
			'dueDate' => new Google_Service_Classroom_Date($dueDate),
			'dueTime' => new Google_Service_Classroom_TimeOfDay($dueTime),
			'maxPoints' => property_exists($c, 'maxPoints') ? $c->maxPoints : 100,
			'workType' => property_exists($c, 'workType') ? $c->workType : 'ASSIGNMENT',
			'topicId' => $topicId
		]);

		$res = $service->courses_courseWork->create($courseId, $body);
	}
}

$client = getClient();
$service = new Google_Service_Classroom($client);

$schedules = json_decode(file_get_contents(__DIR__ . '/schedules.json'));
$courseId = getCourseTopic($service, 'course', $schedules->course);

$nowDate = date('Y-m-d');
if (property_exists($schedules->schedules, $nowDate)) {
	$schedule = $schedules->schedules->{$nowDate};

	$topicId = getCourseTopic($service, 'topic', $schedule[0], $courseId);
	if (!$topicId) {
		$topicId = createTopic($service, $courseId, $schedule[0]);
	}

	$topicCourseWorks = json_decode(file_get_contents(__DIR__ . '/courseWorks.json'))->{$schedule[0]};

	$nowTime = (int)date('H');

	if ($nowTime < 12) {
		createCourseWorks($service, $courseId, $topicId, $topicCourseWorks->{$schedule[1]});
	} else if (count($schedule) >= 3) {
		createCourseWorks($service, $courseId, $topicId, $topicCourseWorks->{$schedule[2]});
	}
}

echo "\n";

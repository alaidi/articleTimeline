<?php

/**
 * @file plugins/generic/articleTimeline/tests/AuthorRevisionsTest.php
 *
 * Copyright (c) 2026 Abdul Hadi Alaidi
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class AuthorRevisionsTest
 *
 * @brief Which uploads count for the Revised date, against the database of the OJS
 *        installation (MySQL or PostgreSQL): the rows each test adds are rolled back.
 */

namespace APP\plugins\generic\articleTimeline\tests;

use APP\plugins\generic\articleTimeline\ArticleTimelinePlugin;
use APP\submission\Submission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\security\Role;
use PKP\submissionFile\SubmissionFile;
use PKP\tests\PKPTestCase;

#[CoversClass(ArticleTimelinePlugin::class)]
class AuthorRevisionsTest extends PKPTestCase
{
    private int $submissionId;

    /** @var array<string, int> user ids by name */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();

        $contextId = DB::table('journals')->min('journal_id');
        if ($contextId === null) {
            $this->markTestSkipped('The database has no journal.');
        }
        $this->submissionId = DB::table('submissions')->insertGetId([
            'context_id' => $contextId,
            'stage_id' => WORKFLOW_STAGE_ID_EXTERNAL_REVIEW,
            'status' => Submission::STATUS_QUEUED,
            'submission_progress' => '',
            'locale' => 'en',
            'date_submitted' => '2025-01-02 10:00:00',
        ], 'submission_id');

        foreach (['firstAuthor', 'secondAuthor', 'editor', 'outsider'] as $name) {
            $this->users[$name] = DB::table('users')->insertGetId([
                'username' => uniqid('tl'), // usernames hold 32 characters
                'email' => uniqid($name) . '@example.org',
                'password' => 'x',
                'date_registered' => '2025-01-01 00:00:00',
            ], 'user_id');
        }
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function contextId(): int
    {
        return (int) DB::table('submissions')->where('submission_id', $this->submissionId)->value('context_id');
    }

    /** A user group of the journal with that role, a new one when it has none */
    private function userGroup(int $roleId, bool $new = false): int
    {
        $id = $new ? null : DB::table('user_groups')->where('context_id', $this->contextId())->where('role_id', $roleId)->min('user_group_id');
        return $id ?? DB::table('user_groups')->insertGetId(['context_id' => $this->contextId(), 'role_id' => $roleId], 'user_group_id');
    }

    private function assign(string $user, int $userGroupId): void
    {
        DB::table('stage_assignments')->insert([
            'submission_id' => $this->submissionId,
            'user_group_id' => $userGroupId,
            'user_id' => $this->users[$user],
            'date_assigned' => '2025-01-02 10:00:00',
        ]);
    }

    private function upload(string $user, string $createdAt, int $fileStage = SubmissionFile::SUBMISSION_FILE_REVIEW_REVISION): void
    {
        $fileId = DB::table('files')->insertGetId(['path' => 'timeline-test.pdf', 'mimetype' => 'application/pdf'], 'file_id');
        DB::table('submission_files')->insert([
            'submission_id' => $this->submissionId,
            'file_id' => $fileId,
            'file_stage' => $fileStage,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'uploader_user_id' => $this->users[$user],
        ]);
    }

    /** @return int[] sorted */
    private function authorIds(): array
    {
        $ids = array_map('intval', ArticleTimelinePlugin::authorUserIds($this->submissionId));
        sort($ids);
        return $ids;
    }

    /** The Revised date the plugin shows for the test submission, if any */
    private function revised(bool $anyUploader = false): ?string
    {
        $plugin = new class () extends ArticleTimelinePlugin {
            public function entries(Submission $article, bool $anyUploader): array
            {
                return $this->getTimelineEntries($article, [], $anyUploader);
            }
        };
        $article = new Submission();
        $article->setId($this->submissionId);
        $article->setData('publications', LazyCollection::make([]));

        foreach ($plugin->entries($article, $anyUploader) as $entry) {
            if ($entry['labelKey'] === 'plugins.generic.articleTimeline.revised') {
                return $entry['date'];
            }
        }
        return null;
    }

    public function testEveryAuthorParticipantIsFound(): void
    {
        $this->assign('firstAuthor', $this->userGroup(Role::ROLE_ID_AUTHOR));
        $this->assign('secondAuthor', $this->userGroup(Role::ROLE_ID_AUTHOR, new: true));
        $this->assign('editor', $this->userGroup(Role::ROLE_ID_MANAGER));

        $this->assertSame([$this->users['firstAuthor'], $this->users['secondAuthor']], $this->authorIds());
    }

    public function testAuthorInTwoAuthorGroupsIsListedOnce(): void
    {
        $this->assign('firstAuthor', $this->userGroup(Role::ROLE_ID_AUTHOR));
        $this->assign('firstAuthor', $this->userGroup(Role::ROLE_ID_AUTHOR, new: true));

        $this->assertSame([$this->users['firstAuthor']], $this->authorIds());
    }

    public function testAuthorGroupWithoutTheReviewStageStillCounts(): void
    {
        // OJS 3.5 assigns a participant to the submission, not to a stage
        $authors = $this->userGroup(Role::ROLE_ID_AUTHOR, new: true);
        $this->assertSame(0, DB::table('user_group_stage')->where('user_group_id', $authors)->count());
        $this->assign('firstAuthor', $authors);
        $this->upload('firstAuthor', '2025-03-14 09:00:00');

        $this->assertSame('2025-03-14 09:00:00', $this->revised());
    }

    public function testLatestUploadOfAnyAuthorIsTheRevisedDate(): void
    {
        $authors = $this->userGroup(Role::ROLE_ID_AUTHOR);
        $this->assign('firstAuthor', $authors);
        $this->assign('secondAuthor', $authors);
        $this->upload('firstAuthor', '2025-02-01 09:00:00');
        $this->upload('secondAuthor', '2025-03-14 09:00:00');
        $this->upload('firstAuthor', '2025-02-20 09:00:00', SubmissionFile::SUBMISSION_FILE_INTERNAL_REVIEW_REVISION);

        $this->assertSame('2025-03-14 09:00:00', $this->revised());
    }

    public function testUploadsOfEditorsOutsidersAndOtherAreasDoNotCount(): void
    {
        $this->assign('firstAuthor', $this->userGroup(Role::ROLE_ID_AUTHOR));
        $this->assign('editor', $this->userGroup(Role::ROLE_ID_MANAGER));
        $this->upload('firstAuthor', '2025-03-14 09:00:00');
        $this->upload('firstAuthor', '2025-04-01 09:00:00', SubmissionFile::SUBMISSION_FILE_SUBMISSION);
        $this->upload('editor', '2025-04-02 09:00:00');
        $this->upload('outsider', '2025-04-03 09:00:00');

        $this->assertSame('2025-03-14 09:00:00', $this->revised());
    }

    public function testEditorUploadsCountWhenTheSettingIsOn(): void
    {
        $this->assign('firstAuthor', $this->userGroup(Role::ROLE_ID_AUTHOR));
        $this->assign('editor', $this->userGroup(Role::ROLE_ID_MANAGER));
        $this->upload('firstAuthor', '2025-03-14 09:00:00');
        $this->upload('editor', '2025-04-02 09:00:00');
        $this->upload('editor', '2025-04-05 09:00:00', SubmissionFile::SUBMISSION_FILE_SUBMISSION);

        $this->assertSame('2025-03-14 09:00:00', $this->revised());
        $this->assertSame('2025-04-02 09:00:00', $this->revised(true));
    }

    public function testEditorUploadCountsWithoutAnAuthorParticipantWhenTheSettingIsOn(): void
    {
        $this->assign('editor', $this->userGroup(Role::ROLE_ID_MANAGER));
        $this->upload('editor', '2025-03-14 09:00:00');

        $this->assertNull($this->revised());
        $this->assertSame('2025-03-14 09:00:00', $this->revised(true));
    }

    public function testWithoutAnAuthorParticipantThereIsNoRevisedDate(): void
    {
        $this->assign('editor', $this->userGroup(Role::ROLE_ID_MANAGER));
        $this->upload('editor', '2025-03-14 09:00:00');

        $this->assertSame([], $this->authorIds());
        $this->assertNull($this->revised());
    }
}

<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\ExamQuestionnaire;
use App\Models\Folder;
use App\Models\SchoolYear;
use App\Models\TeachingGuide;
use App\Models\User;
use App\Services\AcademicHierarchyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentRequestFilingTest extends TestCase
{
    use RefreshDatabase;

    public function test_past_deadline_returns_clear_error_and_preserves_selected_recipients(): void
    {
        $dean = User::where('username', 'dean')->firstOrFail();
        $faculty = User::where('username', 'faculty')->firstOrFail();
        $response = $this->actingAs($dean)->from(route('document-requests.index'))->post(route('document-requests.store'), [
            'title' => 'Deadline validation test', 'request_category' => 'general', 'document_type' => 'pdf',
            'recipient_ids' => [$faculty->id], 'due_at' => now()->subHour()->format('Y-m-d\TH:i'),
        ]);
        $response->assertRedirect(route('document-requests.index'))->assertSessionHasErrors('due_at');
        $this->assertSame([$faculty->id], session()->getOldInput('recipient_ids'));
        $this->assertDatabaseMissing('document_requests', ['title' => 'Deadline validation test']);
        $this->get(route('document-requests.index'))->assertOk()
            ->assertSee('The deadline has already passed. Choose a future date and time, or leave it blank for no deadline.')
            ->assertSee('Request not sent. Please correct:')
            ->assertSee('Done selecting')->assertSee('requestSelectedNames', false)
            ->assertSee('Deadline validation test');
    }

    public function test_future_deadline_with_selected_recipient_is_accepted(): void
    {
        $dean = User::where('username', 'dean')->firstOrFail();
        $faculty = User::where('username', 'faculty')->firstOrFail();
        $this->actingAs($dean)->post(route('document-requests.store'), [
            'title' => 'Future deadline test', 'request_category' => 'general', 'document_type' => 'pdf',
            'recipient_ids' => [$faculty->id], 'due_at' => now()->addDay()->format('Y-m-d\TH:i'),
        ])->assertSessionHasNoErrors()->assertRedirect(route('document-requests.index', ['tab' => 'sent']));
        $this->assertDatabaseHas('document_requests', ['title' => 'Future deadline test']);
    }

    public function test_recipient_refresh_includes_new_accounts_and_excludes_inactive_and_self(): void
    {
        $dean = User::where('username', 'dean')->firstOrFail();
        $this->actingAs($dean)->get(route('document-requests.index'))->assertOk();
        $person = User::factory()->create(['username' => 'recipient-refresh-test', 'status' => 'Active', 'role_id' => User::where('username', 'faculty')->value('role_id')]);
        $person->employee()->create(['employee_no' => 'RECIPIENT-TEST-001', 'full_name' => 'New Recipient Test', 'program' => 'BSIT', 'position' => 'Faculty Employee', 'hire_date' => now()]);

        $response = $this->getJson(route('document-requests.recipients'))->assertOk();
        $ids = collect($response->json('people'))->pluck('id');
        $this->assertTrue($ids->contains($person->id));
        $this->assertFalse($ids->contains($dean->id));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $person->update(['status' => 'Inactive']);
        $this->assertFalse(collect($this->getJson(route('document-requests.recipients'))->assertOk()->json('people'))->pluck('id')->contains($person->id));

        // Faculty may request documents from the Secretary only.
        $facultyPeople = collect($this->actingAs(User::where('username', 'faculty')->firstOrFail())->getJson(route('document-requests.recipients'))->assertOk()->json('people'));
        $this->assertSame([User::where('username', 'secretary')->value('id')], $facultyPeople->pluck('id')->all());
    }

    public function test_recipient_refresh_honors_coordinator_home_and_extra_programs(): void
    {
        $coordinator = User::where('username', 'coordinator')->firstOrFail();
        $program = collect(\App\Models\Program::codes())->first(fn ($code) => $code !== $coordinator->employee->program);
        $person = User::factory()->create(['username' => 'recipient-program-test', 'status' => 'Active', 'role_id' => User::where('username', 'faculty')->value('role_id')]);
        $person->employee()->create(['employee_no' => 'RECIPIENT-TEST-002', 'full_name' => 'Other Program Recipient', 'program' => $program, 'position' => 'Faculty Employee', 'hire_date' => now()]);
        $this->actingAs($coordinator);
        $this->assertFalse(collect($this->getJson(route('document-requests.recipients'))->assertOk()->json('people'))->pluck('id')->contains($person->id));
        $coordinator->employee->syncExtraPrograms([$program]);
        $coordinator->unsetRelation('employee');
        $this->assertTrue(collect($this->getJson(route('document-requests.recipients'))->assertOk()->json('people'))->pluck('id')->contains($person->id));
    }

    public function test_dean_sees_category_and_compact_destination_controls(): void
    {
        $dean = User::where('username', 'dean')->firstOrFail();

        $this->actingAs($dean)
            ->get(route('document-requests.index'))
            ->assertOk()
            ->assertSee('Request category')
            ->assertSee('Teaching Guide')
            ->assertSee('Exam Questionnaire')
            ->assertSee('Choose destination')
            ->assertSee('Use this destination')
            ->assertDontSee('Search available destinations');
    }

    public function test_general_request_is_course_independent_and_has_no_destination(): void
    {
        $dean = User::where('username', 'dean')->firstOrFail();
        $faculty = User::where('username', 'faculty')->firstOrFail();

        $response = $this->actingAs($dean)->post(route('document-requests.store'), [
            'title' => 'Updated personal data sheet',
            'instructions' => 'Submit the signed copy.',
            'document_type' => 'pdf',
            'request_category' => 'general',
            'recipient_ids' => [$faculty->id],
            'course_id' => Course::active()->value('id'),
            'destination_folder_id' => Folder::whereNotNull('parent_id')->value('folder_id'),
        ]);

        $response->assertRedirect(route('document-requests.index', ['tab' => 'sent']));
        $this->assertDatabaseHas('document_requests', [
            'title' => 'Updated personal data sheet',
            'request_category' => 'general',
            'course_id' => null,
            'destination_folder_id' => null,
        ]);
    }

    public function test_course_request_rejects_a_course_not_assigned_to_every_recipient(): void
    {
        $dean = User::where('username', 'dean')->firstOrFail();
        $faculty = User::where('username', 'faculty')->firstOrFail();
        $course = Course::active()->where('program', $faculty->employee->program)->ordered()->firstOrFail();
        $schoolYear = SchoolYear::active();

        $response = $this->actingAs($dean)->from(route('document-requests.index'))->post(route('document-requests.store'), [
            'title' => 'Teaching guide submission',
            'document_type' => 'pdf',
            'request_category' => 'teaching_guide',
            'recipient_ids' => [$faculty->id],
            'course_id' => $course->id,
            'school_year_id' => $schoolYear->id,
            'semester' => '1st',
            'destination_type' => 'tg',
        ]);

        $response->assertRedirect(route('document-requests.index'));
        $response->assertSessionHasErrors('course_id');
        $this->assertDatabaseMissing('document_requests', ['title' => 'Teaching guide submission']);
    }

    public function test_structured_destination_choices_resolve_the_folder_on_the_server(): void
    {
        $dean = User::where('username', 'dean')->firstOrFail();
        $faculty = User::where('username', 'faculty')->firstOrFail();
        $course = Course::active()->where('program', $faculty->employee->program)->ordered()->firstOrFail();
        $schoolYear = SchoolYear::active();
        $faculty->assignedCourses()->sync([$course->id]);

        $this->actingAs($dean)->post(route('document-requests.store'), [
            'title' => 'Requested laboratory manual',
            'document_type' => 'word',
            'request_category' => 'teaching_guide',
            'recipient_ids' => [$faculty->id],
            'course_id' => $course->id,
            'school_year_id' => $schoolYear->id,
            'semester' => '1st',
            'destination_type' => 'lb',
        ])->assertRedirect(route('document-requests.index', ['tab' => 'sent']));

        $request = DocumentRequest::where('title', 'Requested laboratory manual')->firstOrFail();
        $this->assertNotNull($request->destination_folder_id);
        $this->assertSame('LB', $request->destinationFolder->folder_name);
        $this->assertTrue(app(AcademicHierarchyService::class)->isTgUploadLeafFolder($request->destinationFolder));
    }

    public function test_document_request_approval_is_the_only_teaching_guide_approval(): void
    {
        $dean = User::where('username', 'dean')->firstOrFail();
        $faculty = User::where('username', 'faculty')->firstOrFail();
        [$course, $leaf] = $this->teachingGuideDestination();
        $faculty->assignedCourses()->sync([$course->id]);

        $request = DocumentRequest::create([
            'requested_by' => $dean->id,
            'title' => 'Course teaching guide',
            'document_type' => 'pdf',
            'request_category' => 'teaching_guide',
            'course_id' => $course->id,
            'destination_folder_id' => $leaf->folder_id,
            'school_year_id' => $leaf->school_year_id,
            'semester' => '1st',
        ]);
        $document = Document::create([
            'uploaded_by' => $faculty->id,
            'folder_id' => $leaf->folder_id,
            'document_title' => $request->title,
            'file_path' => 'documents/requests/test.pdf',
            'file_size' => 10,
            'document_type' => 'pdf',
            'category' => 'Teaching Guides',
            'school_year_id' => $leaf->school_year_id,
            'subject' => $course->code,
            'tags' => 'document-request,requested-by-dean',
        ]);
        $document->recipients()->sync([$dean->id]);
        $recipient = $request->recipients()->create([
            'user_id' => $faculty->id,
            'submitted_document_id' => $document->document_id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $this->assertSame(0, Document::query()->onlyApprovedShareable()->whereKey($document->document_id)->count());
        $this->assertSame(0, TeachingGuide::where('status', 'pending')->count());

        $this->actingAs($dean)
            ->post(route('document-requests.review', $recipient), ['decision' => 'approved'])
            ->assertRedirect();

        $this->assertDatabaseHas('document_request_recipients', ['id' => $recipient->id, 'status' => 'approved']);
        $this->assertDatabaseHas('teaching_guides', [
            'document_id' => $document->document_id,
            'status' => 'approved',
            'reviewed_by' => $dean->id,
        ]);
        $this->assertSame(0, TeachingGuide::where('status', 'pending')->count());
        $this->assertSame(1, Document::query()->onlyApprovedShareable()->whereKey($document->document_id)->count());
    }

    public function test_general_requested_document_stays_uncategorized_and_hidden_until_approved(): void
    {
        $dean = User::where('username', 'dean')->firstOrFail();
        $faculty = User::where('username', 'faculty')->firstOrFail();
        $request = DocumentRequest::create([
            'requested_by' => $dean->id,
            'title' => 'Signed clearance',
            'document_type' => 'pdf',
            'request_category' => 'general',
        ]);
        $document = Document::create([
            'uploaded_by' => $faculty->id,
            'folder_id' => null,
            'document_title' => $request->title,
            'file_path' => 'documents/requests/clearance.pdf',
            'file_size' => 10,
            'document_type' => 'pdf',
            'category' => 'Other',
            'tags' => 'document-request,requested-by-dean,Signed clearance',
        ]);
        $recipient = $request->recipients()->create([
            'user_id' => $faculty->id,
            'submitted_document_id' => $document->document_id,
            'status' => 'submitted',
        ]);

        $this->assertSame(0, Document::query()->onlyApprovedShareable()->whereKey($document->document_id)->count());
        $this->actingAs($dean)->post(route('document-requests.review', $recipient), ['decision' => 'approved'])->assertRedirect();

        $this->assertDatabaseHas('documents', ['document_id' => $document->document_id, 'folder_id' => null, 'category' => 'Other']);
        $this->assertSame(1, Document::query()->onlyApprovedShareable()->whereKey($document->document_id)->count());
        $this->assertSame('Requested by Dean', $document->fresh()->requested_origin_label);
    }

    public function test_exam_request_approval_creates_only_an_approved_questionnaire(): void
    {
        $dean = User::where('username', 'dean')->firstOrFail();
        $faculty = User::where('username', 'faculty')->firstOrFail();
        $course = Course::active()->ordered()->firstOrFail();
        $schoolYear = SchoolYear::active();
        $hierarchy = app(AcademicHierarchyService::class);
        $hierarchy->ensureSchoolYearStructure('eq', $schoolYear->start_year);
        $semester = Folder::where('slug', 'eq-1st-'.$schoolYear->start_year.'-'.$schoolYear->end_year)->firstOrFail();
        $subject = $hierarchy->ensureSubjectWithEqStructure($semester, $course->code.' — '.$course->title);
        $assessment = $subject->children()->where('folder_name', 'Finals')->firstOrFail();
        $leaf = $assessment->children()->where('folder_name', 'TOQ')->firstOrFail();

        $request = DocumentRequest::create([
            'requested_by' => $dean->id,
            'title' => 'Final examination questionnaire',
            'document_type' => 'pdf',
            'request_category' => 'exam_questionnaire',
            'course_id' => $course->id,
            'destination_folder_id' => $leaf->folder_id,
            'school_year_id' => $schoolYear->id,
            'semester' => '1st',
        ]);
        $document = Document::create([
            'uploaded_by' => $faculty->id,
            'folder_id' => $leaf->folder_id,
            'document_title' => $request->title,
            'file_path' => 'documents/requests/exam.pdf',
            'file_size' => 10,
            'document_type' => 'pdf',
            'category' => 'Exam Questionnaires',
            'school_year_id' => $schoolYear->id,
            'subject' => $course->code,
            'tags' => 'document-request,requested-by-dean',
        ]);
        $recipient = $request->recipients()->create([
            'user_id' => $faculty->id,
            'submitted_document_id' => $document->document_id,
            'status' => 'submitted',
        ]);

        $this->actingAs($dean)->post(route('document-requests.review', $recipient), ['decision' => 'approved'])->assertRedirect();

        $this->assertDatabaseHas('exam_questionnaires', [
            'document_id' => $document->document_id,
            'status' => 'approved',
            'reviewed_by' => $dean->id,
        ]);
        $this->assertSame(0, ExamQuestionnaire::where('status', 'pending')->count());
    }

    private function teachingGuideDestination(): array
    {
        $course = Course::active()->ordered()->firstOrFail();
        $schoolYear = SchoolYear::active();
        $hierarchy = app(AcademicHierarchyService::class);
        $hierarchy->ensureSchoolYearStructure('tg', $schoolYear->start_year);
        $semester = Folder::where('slug', 'tg-1st-'.$schoolYear->start_year.'-'.$schoolYear->end_year)->firstOrFail();
        $subject = $hierarchy->ensureSubjectWithTgLb($semester, $course->code.' — '.$course->title);
        $leaf = $subject->children()->where('folder_name', 'TG')->firstOrFail();

        return [$course, $leaf];
    }
}

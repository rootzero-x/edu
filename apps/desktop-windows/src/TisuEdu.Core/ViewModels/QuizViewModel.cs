using System.Collections.ObjectModel;
using System.Globalization;
using System.Text.Json;
using CommunityToolkit.Mvvm.ComponentModel;
using CommunityToolkit.Mvvm.Input;
using TisuEdu.Core.Api;
using TisuEdu.Core.Models;
using TisuEdu.Core.Services;

namespace TisuEdu.Core.ViewModels;

public sealed partial class OptionItem(QuizOption o) : ObservableObject
{
    public int Id { get; } = o.Id;
    public string Text { get; } = o.Text;

    [ObservableProperty] private bool _isSelected;

    /// <summary>Tekshiruvdan keyin: to'g'ri variant.</summary>
    [ObservableProperty] private bool _isCorrectAnswer;
}

public sealed partial class QuestionItem : ObservableObject
{
    public QuestionItem(QuizQuestion q, int index, int total, SiteSettings st)
    {
        Question = q;
        Header = st.Text("question", "Savol {n}/{total}", ("n", index + 1), ("total", total));
        Options = q.Options.Select(o => new OptionItem(o)).ToList();
        MultipleHint = st.Text("quiz_multiple_hint", "Bir nechta javob tanlash mumkin");
        TextPlaceholder = st.Text("quiz_text_answer", "Javobingiz");
        foreach (var o in Options) o.PropertyChanged += (_, e) => { if (e.PropertyName == nameof(OptionItem.IsSelected)) OnPropertyChanged(nameof(IsAnswered)); };
    }

    public QuizQuestion Question { get; }
    public string Header { get; }
    public string Text => Question.Text;
    public string PointsText => $"{Question.Points} ball";
    public bool IsSingle => Question.Type == "SINGLE";
    public bool IsMultiple => Question.Type == "MULTIPLE";
    public bool IsText => Question.Type == "TEXT";
    public string MultipleHint { get; }
    public string TextPlaceholder { get; }
    public IReadOnlyList<OptionItem> Options { get; }

    /// <summary>SINGLE uchun RadioButtons.SelectedIndex.</summary>
    [ObservableProperty]
    [NotifyPropertyChangedFor(nameof(IsAnswered))]
    private int _selectedIndex = -1;

    [ObservableProperty]
    [NotifyPropertyChangedFor(nameof(IsAnswered))]
    private string _textAnswer = "";

    // Natija ko'rinishi
    [ObservableProperty]
    [NotifyPropertyChangedFor(nameof(CanAnswer))]
    private bool _isReviewed;

    public bool CanAnswer => !IsReviewed;
    [ObservableProperty] private bool _isCorrect;
    [ObservableProperty] private string _reviewText = "";

    public bool IsAnswered => IsText ? TextAnswer.Trim().Length > 0 : IsSingle ? SelectedIndex >= 0 : Options.Any(o => o.IsSelected);

    public List<string> Answer()
    {
        if (IsText) return TextAnswer.Trim().Length > 0 ? [TextAnswer.Trim()] : [];
        if (IsSingle) return SelectedIndex >= 0 && SelectedIndex < Options.Count ? [Options[SelectedIndex].Id.ToString(CultureInfo.InvariantCulture)] : [];
        return Options.Where(o => o.IsSelected).Select(o => o.Id.ToString(CultureInfo.InvariantCulture)).ToList();
    }

    public void ApplyReview(ReviewItem r, SiteSettings st)
    {
        IsReviewed = true;
        IsCorrect = r.Earned >= r.Points && r.Points > 0;
        var correct = r.Correct.Select(e => e.ValueKind == JsonValueKind.Number ? e.GetRawText() : e.GetString() ?? "").ToHashSet();
        foreach (var o in Options) o.IsCorrectAnswer = correct.Contains(o.Id.ToString(CultureInfo.InvariantCulture));
        ReviewText = (IsCorrect ? st.Text("correct", "To'g'ri") : st.Text("wrong", "Noto'g'ri"))
            + (IsText && !IsCorrect && correct.Count > 0 ? " — " + string.Join(" / ", correct) : "")
            + $" · {r.Earned}/{r.Points}";
    }
}

/// <summary>Boshqaruvchi ko'rinishidagi savol (javob kaliti bilan).</summary>
public sealed class QuestionKeyItem(QuizQuestion q, int index)
{
    public QuizQuestion Question { get; } = q;
    public bool CanEdit { get; init; }
    public string Header => $"{index + 1}. {q.Text}";
    public string Meta => (q.Type switch { "SINGLE" => "Bitta javob", "MULTIPLE" => "Bir nechta javob", _ => "Matnli javob" }) + $" · {q.Points} ball";
    public string Answers => q.Type == "TEXT"
        ? "Javob: " + string.Join(" / ", q.AcceptedAnswers ?? [])
        : string.Join("\n", q.Options.Select(o => (o.IsCorrect == true ? "✓ " : "○ ") + o.Text));
}

public sealed class AttemptItem(AttemptSummary a)
{
    public string When => Format.DateTime(a.SubmittedAt ?? a.StartedAt);
    public string Score => a.SubmittedAt is null ? "jarayonda" : $"{a.Score}/{a.MaxScore} · {Format.Percent(a.Percent)}";
    public bool Passed => a.Passed == true;
    public bool Open => a.SubmittedAt is null;
}

/// <summary>
/// Test: o'quvchi — boshlash, javob berish, vaqt hisoblagichi, topshirish, natija va tekshiruv;
/// boshqaruvchi — natijalar jadvali (tahrirlash saytda).
/// </summary>
public sealed partial class QuizViewModel(AppServices services, int quizId) : PageViewModel(services)
{
    private Attempt? _attempt;
    private CancellationTokenSource? _timerCts;

    public ObservableCollection<AttemptItem> Attempts { get; } = [];
    public ObservableCollection<QuestionItem> Questions { get; } = [];
    public ObservableCollection<QuizResultRow> Results { get; } = [];
    public ObservableCollection<QuestionKeyItem> KeyQuestions { get; } = [];

    private QuizInfo? _info;

    [ObservableProperty] private bool _canEdit;
    [ObservableProperty] private bool _hasKeyQuestions;

    [RelayCommand]
    private Task EditQuizAsync() => _info is null ? Task.CompletedTask : EditAsync("Test sozlamalari", Editing.QuizForm(_info, 60, 3),
        v => S.Api.UpdateQuizAsync(quizId, Editing.QuizBody(v)), "Test saqlandi.");

    [RelayCommand]
    private Task DeleteQuizAsync() => ConfirmActAsync("Testni o'chirish", $"«{Title}» testi o'chirilsinmi? Topshirganlar bo'lsa, o'chirish o'rniga yashiring.", "O'chirish",
        async () => { await S.Api.DeleteQuizAsync(quizId); S.Navigator.GoBack(); }, "Test o'chirildi.", reload: false);

    [RelayCommand]
    private async Task AddQuestionAsync()
    {
        var ok = await S.Forms.ShowAsync("Yangi savol", Editing.QuestionForm(null), v =>
        {
            var (body, error) = Editing.QuestionBody(v, null);
            return body is null ? Task.FromResult(error) : Submit(() => S.Api.CreateQuestionAsync(quizId, body));
        }, "Qo'shish");
        if (ok) { await RefreshAsync(); Notice = "Savol qo'shildi."; }
    }

    [RelayCommand]
    private async Task EditQuestionAsync(QuestionKeyItem? item)
    {
        if (item is null) return;
        var q = item.Question;
        var ok = await S.Forms.ShowAsync("Savolni tahrirlash", Editing.QuestionForm(q), v =>
        {
            var (body, error) = Editing.QuestionBody(v, q.Type);
            return body is null ? Task.FromResult(error) : Submit(() => S.Api.UpdateQuestionAsync(q.Id, body));
        });
        if (ok) { await RefreshAsync(); Notice = "Savol saqlandi."; }
    }

    [RelayCommand]
    private Task DeleteQuestionAsync(QuestionKeyItem? item) => item is null ? Task.CompletedTask : ConfirmActAsync("Savolni o'chirish",
        "Savol o'chirilsinmi?", "O'chirish", () => S.Api.DeleteQuestionAsync(item.Question.Id), "Savol o'chirildi.");

    [ObservableProperty] private string _title = "";
    [ObservableProperty] private string _courseTitle = "";
    [ObservableProperty] private string _meta = "";
    [ObservableProperty] private bool _isManager;
    [ObservableProperty] private bool _isStudent;
    [ObservableProperty] private bool _canTake;
    [ObservableProperty] private bool _notAllowed;
    [ObservableProperty] private string _startText = "";
    [ObservableProperty] private bool _hasAttempts;
    [ObservableProperty] private bool _hasResults;

    // Topshirish rejimi
    [ObservableProperty]
    [NotifyPropertyChangedFor(nameof(IsOverview), nameof(ShowQuestions))]
    private bool _isTaking;

    [ObservableProperty] private string _timeLeft = "";
    [ObservableProperty] private bool _hasTimer;
    [ObservableProperty] private bool _timeLow;

    // Natija
    [ObservableProperty]
    [NotifyPropertyChangedFor(nameof(IsOverview), nameof(ShowQuestions))]
    private bool _hasResult;

    [ObservableProperty] private string _resultTitle = "";
    [ObservableProperty] private string _resultText = "";
    [ObservableProperty] private bool _resultPassed;
    [ObservableProperty]
    [NotifyPropertyChangedFor(nameof(ShowQuestions))]
    private bool _hasReview;

    public bool ShowQuestions => IsTaking || (HasResult && HasReview);

    private bool _canStart;

    public bool IsOverview => !IsTaking && !HasResult;
    public string SubmitText => Settings.Text("quiz_submit", "Topshirish");
    public string NotAllowedText => Settings.Text("quiz_not_allowed", "Bu test sizning guruhingiz uchun emas.");
    public string ManageOnWebText => Settings.Text("quiz_manage_on_web", "Testni tahrirlash va natijalarni ko'rish — saytdagi boshqaruv panelida.");
    public string OpenOnWebText => Settings.Text("open_on_web", "Saytda ochish");
    public string BackText => Settings.Text("quiz_back", "Test sahifasiga qaytish");

    protected override async Task LoadCoreAsync(CancellationToken ct)
    {
        var d = await S.Api.QuizAsync(quizId, ct);
        var q = d.Quiz;
        _info = q;
        CanEdit = d.CanEdit == true;
        KeyQuestions.Clear();
        foreach (var (kq, i) in (q.Questions ?? []).Select((x, i) => (x, i))) KeyQuestions.Add(new QuestionKeyItem(kq, i) { CanEdit = d.CanEdit == true });
        HasKeyQuestions = KeyQuestions.Count > 0;
        Title = q.Title;
        CourseTitle = q.Course?.Title ?? "";
        Meta = string.Join(" · ", new[]
        {
            Settings.Text("quiz_questions", "{n} ta savol", ("n", q.Questions?.Count ?? q.QuestionsCount)),
            q.TimeLimitSec is { } t ? Settings.Text("quiz_time", "{min} daqiqa", ("min", Math.Max(1, t / 60))) : Settings.Text("quiz_no_time", "Vaqt cheklanmagan"),
            Settings.Text("quiz_pass", "O'tish: {percent}%", ("percent", q.PassPercent)),
            Settings.Text("quiz_attempts", "Urinishlar: {used}/{max}", ("used", d.Attempts.Count(a => a.SubmittedAt is not null)), ("max", q.MaxAttempts)),
        });

        IsManager = d.CanTake is null;
        IsStudent = !IsManager;
        Attempts.Clear();
        foreach (var a in d.Attempts) Attempts.Add(new AttemptItem(a));
        HasAttempts = Attempts.Count > 0;

        if (IsStudent)
        {
            var used = d.Attempts.Count(a => a.SubmittedAt is not null);
            var hasOpen = d.Attempts.Any(a => a.SubmittedAt is null);
            CanTake = d.CanTake == true && (hasOpen || used < q.MaxAttempts);
            NotAllowed = d.CanTake != true;
            StartText = hasOpen ? Settings.Text("quiz_continue", "Davom ettirish") : Settings.Text("quiz_start", "Testni boshlash");
        }
        else
        {
            Results.Clear();
            try
            {
                var r = await S.Api.QuizResultsAsync(quizId, ct);
                foreach (var row in r.Data) Results.Add(row);
            }
            catch (ApiException) { /* natijalar faqat kurs muallifi/o'qituvchiga */ }
            HasResults = Results.Count > 0;
        }
        _canStart = CanTake;
        StartCommand.NotifyCanExecuteChanged();
    }

    protected override void OnBusyChanged() => StartCommand.NotifyCanExecuteChanged();

    private bool CanStartAttempt() => _canStart && !IsBusy;

    [RelayCommand(CanExecute = nameof(CanStartAttempt))]
    private async Task StartAsync()
    {
        await ActAsync(async () =>
        {
            var a = await S.Api.StartAttemptAsync(quizId);
            _attempt = a;
            Questions.Clear();
            for (var i = 0; i < a.Questions.Count; i++) Questions.Add(new QuestionItem(a.Questions[i], i, a.Questions.Count, Settings));
            HasResult = false;
            IsTaking = true;
            StartTimer(a.Deadline);
        });
    }

    private void StartTimer(DateTimeOffset? deadline)
    {
        _timerCts?.Cancel();
        HasTimer = deadline is not null;
        if (deadline is null) return;
        _timerCts = new CancellationTokenSource();
        _ = TickAsync(deadline.Value, _timerCts.Token);
    }

    private async Task TickAsync(DateTimeOffset deadline, CancellationToken ct)
    {
        while (!ct.IsCancellationRequested)
        {
            var left = deadline - DateTimeOffset.UtcNow;
            if (left <= TimeSpan.Zero)
            {
                TimeLeft = Format.Duration(TimeSpan.Zero);
                await SubmitCoreAsync(); // vaqt tugadi — avtomatik topshirish
                return;
            }
            TimeLeft = Settings.Text("quiz_time_left", "Qolgan vaqt: {time}", ("time", Format.Duration(left)));
            TimeLow = left < TimeSpan.FromMinutes(1);
            try { await Task.Delay(TimeSpan.FromSeconds(1), ct); }
            catch (OperationCanceledException) { return; }
        }
    }

    [RelayCommand]
    private async Task SubmitAsync()
    {
        var unanswered = Questions.Count(q => !q.IsAnswered);
        var message = unanswered > 0
            ? Settings.Text("quiz_unanswered", "{n} ta savolga javob berilmagan. Baribir topshirasizmi?", ("n", unanswered))
            : Settings.Text("quiz_confirm_submit", "Javoblarni topshirasizmi?");
        if (!await S.Platform.ConfirmAsync(SubmitText, message, SubmitText, "Bekor qilish")) return;
        await SubmitCoreAsync();
    }

    private async Task SubmitCoreAsync()
    {
        if (_attempt is null || IsBusy) return;
        var answers = Questions.ToDictionary(q => q.Question.Id.ToString(CultureInfo.InvariantCulture), q => q.Answer());
        await ActAsync(async () =>
        {
            var r = await S.Api.SubmitAttemptAsync(_attempt.Id, answers);
            _timerCts?.Cancel();
            _attempt = null;
            ShowResult(r);
        });
    }

    internal void ShowResult(SubmitResponse r)
    {
        var res = r.Result;
        ResultPassed = res.Passed;
        ResultTitle = res.TimeExpired ? Settings.Text("quiz_time_expired", "Vaqt tugagan edi — javoblar hisobga olinmadi.")
            : res.Passed ? Settings.Text("quiz_passed", "Tabriklaymiz, testdan o'tdingiz!") : Settings.Text("quiz_failed", "Afsuski, o'tish bali yetmadi.");
        ResultText = Settings.Text("quiz_result", "Natija: {score}/{max} ({percent}%)",
            ("score", res.Score), ("max", res.MaxScore), ("percent", res.Percent.ToString("0.#", CultureInfo.InvariantCulture)));
        HasReview = r.Review is { Count: > 0 };
        if (r.Review is not null)
        {
            var byId = r.Review.ToDictionary(x => x.QuestionId);
            foreach (var q in Questions)
                if (byId.TryGetValue(q.Question.Id, out var item)) q.ApplyReview(item, Settings);
        }
        IsTaking = false;
        HasResult = true;
    }

    [RelayCommand]
    private async Task BackToOverviewAsync()
    {
        HasResult = false;
        IsTaking = false;
        Questions.Clear();
        await RefreshAsync();
    }

    [RelayCommand]
    private void OpenOnWeb() => S.Platform.Open(new Uri($"{Settings.SiteUrl}/dashboard/quizzes/{quizId}"));

    public override void Leave()
    {
        _timerCts?.Cancel();
        base.Leave();
    }
}

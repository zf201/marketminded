package store

// PipelineStore handles pipeline runs and steps.
type PipelineStore interface {
	CreatePipelineRun(projectID int64, brief string) (*PipelineRun, error)
	GetPipelineRun(id int64) (*PipelineRun, error)
	ListPipelineRuns(projectID int64) ([]PipelineRun, error)
	UpdatePipelineTopic(id int64, topic string) error
	UpdatePipelineStatus(id int64, status string) error
	UpdatePipelinePlan(id int64, plan string) error
	UpdatePipelinePhase(id int64, phase string) error
	DeletePipelineRun(id int64) error
	CreatePipelineStep(pipelineRunID int64, stepType string, sortOrder int) (*PipelineStep, error)
	GetPipelineStep(id int64) (*PipelineStep, error)
	ListPipelineSteps(pipelineRunID int64) ([]PipelineStep, error)
	TrySetStepRunning(id int64) (bool, error)
	UpdatePipelineStepStatus(id int64, status string) error
	UpdatePipelineStepOutput(id int64, output, thinking string) error
	UpdatePipelineStepInput(id int64, input string) error
	UpdatePipelineStepToolCalls(id int64, toolCalls string) error
}

// ContentStore handles content pieces.
type ContentStore interface {
	CreateContentPiece(projectID, pipelineRunID int64, platform, format, title string, sortOrder int, parentID *int64) (*ContentPiece, error)
	GetContentPiece(id int64) (*ContentPiece, error)
	ListContentByPipelineRun(runID int64) ([]ContentPiece, error)
	NextPendingPiece(runID int64) (*ContentPiece, error)
	UpdateContentPieceBody(id int64, title, body string) error
	SetContentPieceStatus(id int64, status string) error
	SetContentPieceRejection(id int64, reason string) error
	TrySetGenerating(id int64) (bool, error)
	AllPiecesApproved(runID int64) (bool, error)
}

// ProfileStore handles brand profile sections and string building.
type ProfileStore interface {
	UpsertProfileSection(projectID int64, section, content string) error
	UpsertProfileSectionFull(projectID int64, section, content, sourceURLs string) error
	GetProfileSection(projectID int64, section string) (*ProfileSection, error)
	ListProfileSections(projectID int64) ([]ProfileSection, error)
	BuildProfileString(projectID int64) (string, error)
	BuildProfileStringExcluding(projectID int64, exclude []string) (string, error)
	BuildSourceURLList(projectID int64) (string, error)
	SaveProfileVersion(projectID int64, section, content string) error
	ListProfileVersions(projectID int64, section string) ([]ProfileVersion, error)
}

// ProjectStore handles projects.
type ProjectStore interface {
	CreateProject(name, description string) (*Project, error)
	GetProject(id int64) (*Project, error)
	ListProjects() ([]Project, error)
	DeleteProject(id int64) error
}

// SettingsStore handles global app settings.
type SettingsStore interface {
	GetSetting(key string) (string, error)
	SetSetting(key, value string) error
	AllSettings() (map[string]string, error)
}

// ProjectSettingsStore handles per-project key-value settings.
type ProjectSettingsStore interface {
	GetProjectSetting(projectID int64, key string) (string, error)
	SetProjectSetting(projectID int64, key, value string) error
	AllProjectSettings(projectID int64) (map[string]string, error)
}

// BrainstormStore handles brainstorm chats and messages.
type BrainstormStore interface {
	CreateBrainstormChat(projectID int64, title, section string, contentPieceID *int64) (*BrainstormChat, error)
	GetBrainstormChat(id int64) (*BrainstormChat, error)
	ListBrainstormChats(projectID int64) ([]BrainstormChat, error)
	AddBrainstormMessage(chatID int64, role, content, thinking string) (*BrainstormMessage, error)
	ListBrainstormMessages(chatID int64) ([]BrainstormMessage, error)
	GetOrCreateProfileChat(projectID int64) (*BrainstormChat, error)
	GetOrCreateContextChat(projectID, contextItemID int64) (*BrainstormChat, error)
	GetOrCreateSectionChat(projectID int64, section string) (*BrainstormChat, error)
	GetOrCreatePieceChat(projectID, pieceID int64) (*BrainstormChat, error)
}

// ContextStore handles custom knowledge items.
type ContextStore interface {
	CreateContextItem(projectID int64, title string) (*ContextItem, error)
	GetContextItem(id int64) (*ContextItem, error)
	UpdateContextItem(id int64, title, content string) error
	DeleteContextItem(id int64) error
	ListContextItems(projectID int64) ([]ContextItem, error)
	BuildContextString(projectID int64) (string, error)
}

// Ensure Queries implements all interfaces at compile time.
var _ PipelineStore = (*Queries)(nil)
var _ ContentStore = (*Queries)(nil)
var _ ProfileStore = (*Queries)(nil)
var _ ProjectStore = (*Queries)(nil)
var _ SettingsStore = (*Queries)(nil)
var _ ProjectSettingsStore = (*Queries)(nil)
var _ BrainstormStore = (*Queries)(nil)
var _ ContextStore = (*Queries)(nil)

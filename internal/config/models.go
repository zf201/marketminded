package config

// ResolveModel returns the first non-empty value: DB setting, config default.
func ResolveModel(dbValue, configDefault string) string {
	if dbValue != "" {
		return dbValue
	}
	return configDefault
}

#!/usr/bin/env Rscript

# Default to the attached file path, but allow override via CLI argument.
args <- commandArgs(trailingOnly = TRUE)
input_file <- if (length(args) >= 1 && nzchar(args[1])) {
  args[1]
} else {
  "/Users/pp3223/Downloads/DA-pp3223_gmail_com_s_Intubacao_Orotraqueal_em_Simulador_com_Feedback_Luminoso_at_20260805_20_25-20260806-105403-TESTING.csv"
}

if (!file.exists(input_file)) {
  stop(paste("Input file not found:", input_file))
}

da <- read.csv(input_file, stringsAsFactors = FALSE, check.names = FALSE)

required_col <- "student_uri"
if (!(required_col %in% names(da))) {
  stop("CSV must include a 'student_uri' column.")
}

# Identify task columns dynamically.
datetime_cols <- grep("^task_[0-9]+_datetime$", names(da), value = TRUE)
result_cols <- grep("^task_[0-9]+_result$", names(da), value = TRUE)

if (length(datetime_cols) == 0 || length(result_cols) == 0) {
  stop("CSV must include task datetime/result columns like task_001_datetime and task_001_result.")
}

extract_task_num <- function(name_vec) {
  as.integer(sub("^task_([0-9]+)_(datetime|result)$", "\\1", name_vec))
}

dt_task_nums <- extract_task_num(datetime_cols)
res_task_nums <- extract_task_num(result_cols)
all_task_nums <- sort(unique(c(dt_task_nums, res_task_nums)))

parse_time <- function(x) {
  as.POSIXct(x, tz = "UTC", format = "%Y-%m-%dT%H:%M:%OS")
}

long <- data.frame(
  student_uri = character(0),
  task_num = integer(0),
  task_datetime = as.POSIXct(character(0), tz = "UTC"),
  task_result = character(0),
  stringsAsFactors = FALSE
)

for (i in seq_len(nrow(da))) {
  student <- as.character(da$student_uri[i])
  for (n in all_task_nums) {
    dt_col <- sprintf("task_%03d_datetime", n)
    rs_col <- sprintf("task_%03d_result", n)
    dt_val <- if (dt_col %in% names(da)) as.character(da[[dt_col]][i]) else ""
    rs_val <- if (rs_col %in% names(da)) as.character(da[[rs_col]][i]) else ""
    long <- rbind(
      long,
      data.frame(
        student_uri = student,
        task_num = n,
        task_datetime = parse_time(dt_val),
        task_result = rs_val,
        stringsAsFactors = FALSE
      )
    )
  }
}

long <- long[order(long$student_uri, long$task_num), ]

# (1) Total duration of run for each student.
students <- unique(long$student_uri)
run_duration <- data.frame(
  student_uri = character(0),
  start_time = character(0),
  end_time = character(0),
  total_duration_seconds = numeric(0),
  total_duration_minutes = numeric(0),
  stringsAsFactors = FALSE
)

for (student in students) {
  subset_rows <- long[long$student_uri == student & !is.na(long$task_datetime), ]
  if (nrow(subset_rows) == 0) {
    next
  }
  start_time <- min(subset_rows$task_datetime)
  end_time <- max(subset_rows$task_datetime)
  total_secs <- as.numeric(difftime(end_time, start_time, units = "secs"))
  run_duration <- rbind(
    run_duration,
    data.frame(
      student_uri = student,
      start_time = format(start_time, "%Y-%m-%dT%H:%M:%OSZ"),
      end_time = format(end_time, "%Y-%m-%dT%H:%M:%OSZ"),
      total_duration_seconds = total_secs,
      total_duration_minutes = total_secs / 60,
      stringsAsFactors = FALSE
    )
  )
}

# Approximate response time as time since previous recorded task timestamp.
# For manual tasks, this gives a practical estimate with the available data structure.
long$prev_task_time <- as.POSIXct(NA, tz = "UTC")
long$response_time_seconds <- NA_real_

for (student in students) {
  idx <- which(long$student_uri == student)
  idx <- idx[order(long$task_num[idx])]
  if (length(idx) <= 1) {
    next
  }
  prev_times <- c(NA, long$task_datetime[idx][-length(idx)])
  long$prev_task_time[idx] <- prev_times
  long$response_time_seconds[idx] <- as.numeric(difftime(long$task_datetime[idx], prev_times, units = "secs"))
}

manual_levels <- c("done fully", "done partially", "fail")
task_result_norm <- tolower(trimws(long$task_result))
manual_rows <- long[task_result_norm %in% manual_levels, ]

# (2) Average response time of manual tasks.
valid_manual <- manual_rows[!is.na(manual_rows$response_time_seconds) & manual_rows$response_time_seconds >= 0, ]

if (nrow(valid_manual) > 0) {
  split_by_task <- split(valid_manual$response_time_seconds, valid_manual$task_num)
  manual_response_by_task <- data.frame(
    task_num = as.integer(names(split_by_task)),
    avg_response_seconds = as.numeric(sapply(split_by_task, mean, na.rm = TRUE)),
    n = as.integer(sapply(split_by_task, length)),
    stringsAsFactors = FALSE
  )
  manual_response_by_task <- manual_response_by_task[order(manual_response_by_task$task_num), ]
  overall_manual_avg <- mean(valid_manual$response_time_seconds, na.rm = TRUE)
} else {
  manual_response_by_task <- data.frame(task_num = integer(0), avg_response_seconds = numeric(0), n = integer(0), stringsAsFactors = FALSE)
  overall_manual_avg <- NaN
}

# (3) Success rate pie chart for manual-task outcomes.
outcome_group <- rep("Other", nrow(manual_rows))
manual_norm <- tolower(trimws(manual_rows$task_result))
outcome_group[manual_norm == "done fully"] <- "Success"
outcome_group[manual_norm == "done partially"] <- "Partial"
outcome_group[manual_norm == "fail"] <- "Fail"

counts <- table(factor(outcome_group, levels = c("Success", "Partial", "Fail", "Other")))
success_summary <- data.frame(
  outcome_group = names(counts),
  count = as.numeric(counts),
  stringsAsFactors = FALSE
)
success_summary <- success_summary[success_summary$count > 0, ]
success_summary$percent <- success_summary$count / sum(success_summary$count)

# Output paths.
out_dir <- dirname(input_file)
duration_csv <- file.path(out_dir, "total_duration_by_student.csv")
manual_csv <- file.path(out_dir, "manual_response_summary.csv")
success_csv <- file.path(out_dir, "success_rate_summary.csv")
manual_plot <- file.path(out_dir, "avg_manual_response_time.png")
success_plot <- file.path(out_dir, "success_rate_pie.png")

write.csv(run_duration, duration_csv, row.names = FALSE)
write.csv(manual_response_by_task, manual_csv, row.names = FALSE)
write.csv(success_summary, success_csv, row.names = FALSE)

# Graph: average manual response time by task.
if (nrow(manual_response_by_task) > 0) {
  png(filename = manual_plot, width = 1500, height = 750, res = 150)
  par(mar = c(6, 5, 5, 1))
  mids <- barplot(
    manual_response_by_task$avg_response_seconds,
    names.arg = manual_response_by_task$task_num,
    col = "#2C7FB8",
    xlab = "Task Number",
    ylab = "Average Response Time (seconds)",
    main = "Average Response Time for Manual Tasks"
  )
  text(mids, manual_response_by_task$avg_response_seconds, labels = round(manual_response_by_task$avg_response_seconds, 2), pos = 3, cex = 0.8)
  mtext("Estimated as time since previous recorded task timestamp", side = 3, line = 0.5, cex = 0.8)
  dev.off()
}

# Graph: success rate pie chart.
if (nrow(success_summary) > 0) {
  png(filename = success_plot, width = 1050, height = 1050, res = 150)
  pie(
    success_summary$count,
    labels = paste0(success_summary$outcome_group, "\n", round(success_summary$percent * 100, 1), "%"),
    col = c("#2CA25F", "#FDB863", "#EF6548", "#BDBDBD")[seq_len(nrow(success_summary))],
    main = "Manual Task Outcome Distribution"
  )
  dev.off()
}

cat("Analysis complete. Files generated:\n")
cat("-", duration_csv, "\n")
cat("-", manual_csv, "\n")
cat("-", success_csv, "\n")
if (file.exists(manual_plot)) cat("-", manual_plot, "\n")
if (file.exists(success_plot)) cat("-", success_plot, "\n")

if (!is.nan(overall_manual_avg)) {
  cat("\nOverall average manual response time (seconds): ", round(overall_manual_avg, 2), "\n", sep = "")
}

# Function to count files by extension in the current directory
count_files_by_extension() {
    # Check if the current directory is valid
    if [[ ! -d . ]]; then
        echo "Error: Current directory is invalid."
        return 1
    fi

    # Use find and awk to count files by extension
    find . -type f | awk -F. '
    NF>1 { ext[$NF]++ }
    END {
        for (e in ext) {
            printf "%s: %d\n", e, ext[e]
        }
    }'
}

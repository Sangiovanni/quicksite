/**
 * QuickSite API Endpoints (qs-api-config.js)
 * Auto-generated during build - DO NOT EDIT
 * Generated: 2026-02-06 15:18:07
 */
window.QS_API_ENDPOINTS = {
    "test": {
        "name": "test api",
        "baseUrl": "http://localhost/quick-api",
        "auth": {
            "type": "bearer",
            "tokenSource": "localStorage:your-secret-api-token-here"
        },
        "endpoints": {
            "listFile": {
                "id": "listFile",
                "name": "listFile",
                "path": "/listFile.php",
                "method": "GET",
                "description": "List the files",
                "requestSchema": {
                    "type": "object",
                    "required": [],
                    "properties": {
                        "nameContains": {
                            "type": "string"
                        }
                    }
                },
                "responseSchema": {
                    "type": "object",
                    "required": [
                        "files"
                    ],
                    "properties": {
                        "files": {
                            "type": "array",
                            "items": {
                                "type": "string"
                            }
                        }
                    }
                }
            },
            "addFile": {
                "id": "addFile",
                "name": "addFile",
                "path": "/secure/addFile.php",
                "method": "POST",
                "description": "Add file to the list of file",
                "requestSchema": {
                    "type": "object",
                    "required": [
                        "name"
                    ],
                    "properties": {
                        "name": {
                            "type": "string",
                            "minLength": 1,
                            "maxLength": 100
                        },
                        "content": {
                            "type": "string"
                        }
                    }
                },
                "responseSchema": {
                    "type": "object",
                    "required": [
                        "success",
                        "message"
                    ],
                    "properties": {
                        "success": {
                            "type": "boolean"
                        },
                        "message": {
                            "type": "string"
                        }
                    }
                }
            },
            "deleteFile": {
                "id": "deleteFile",
                "name": "deleteFile",
                "path": "/secure/deleteFile.php",
                "method": "DELETE",
                "description": "delete a file from the list of file",
                "requestSchema": {
                    "type": "object",
                    "required": [
                        "name"
                    ],
                    "properties": {
                        "name": {
                            "type": "string"
                        }
                    }
                },
                "responseSchema": {
                    "type": "object",
                    "required": [
                        "success",
                        "message"
                    ],
                    "properties": {
                        "success": {
                            "type": "boolean"
                        },
                        "message": {
                            "type": "string"
                        }
                    }
                }
            },
            "getFile": {
                "id": "getFile",
                "name": "getFile",
                "path": "/getFile.php",
                "method": "GET",
                "description": "get File content of the given name file",
                "requestSchema": {
                    "type": "object",
                    "required": [
                        "name"
                    ],
                    "properties": {
                        "name": {
                            "type": "string"
                        }
                    }
                },
                "responseSchema": {
                    "type": "object",
                    "required": [
                        "name",
                        "content"
                    ],
                    "properties": {
                        "name": {
                            "type": "string"
                        },
                        "content": {
                            "type": "string"
                        }
                    }
                }
            },
            "updateFile": {
                "id": "updateFile",
                "name": "updateFile",
                "path": "/secure/updateFile.php",
                "method": "PUT",
                "description": "update a file content",
                "requestSchema": {
                    "type": "object",
                    "required": [
                        "name"
                    ],
                    "properties": {
                        "name": {
                            "type": "string",
                            "minLength": 1,
                            "maxLength": 100
                        },
                        "content": {
                            "type": "string"
                        },
                        "method": {
                            "type": "string",
                            "enum": [
                                "replace",
                                "append"
                            ],
                            "default": "replace"
                        }
                    }
                },
                "responseSchema": {
                    "type": "object",
                    "required": [
                        "success",
                        "message",
                        "method"
                    ],
                    "properties": {
                        "success": {
                            "type": "boolean"
                        },
                        "message": {
                            "type": "string"
                        },
                        "method": {
                            "type": "string"
                        }
                    }
                }
            }
        }
    },
    "second-api": {
        "name": "Second api",
        "baseUrl": "https://nothing.nono",
        "auth": {
            "type": "none"
        },
        "endpoints": []
    }
};
